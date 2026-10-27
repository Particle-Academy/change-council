<?php

declare(strict_types=1);

use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Consumer;
use Illuminate\Support\Carbon;

/*
 * Silence is NOT consent, and it is a per-consumer policy (owner ruling
 * q_5e39ab76): `escalate` (the default) sends the Change to the owner;
 * `proceed_and_notify` lets it proceed and keeps the round open for that
 * consumer's late vote. The owner can override per Change.
 */

beforeEach(function (): void {
    councilApp();
});

test('under escalate, a silent required voter first gets ONE extension, then escalates; it never reaches approved', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $change = propose();

    pastDeadline($change);
    ledger()->tally();
    $round = $change->fresh()->currentRound();
    expect($change->fresh()->state)->toBe(States::ROUND1_OPEN)
        ->and($round->extended_until)->not->toBeNull()
        ->and(Carbon::parse($round->closes_at)->diffInHours(Carbon::parse($round->extended_until)))->toEqual(24.0);

    pastDeadline($change);
    ledger()->tally();
    expect($change->fresh()->state)->toBe(States::ESCALATED)
        ->and(petitionFor($change, 'moic@moic-suite')->outcome)->toBe('silent')
        ->and($change->fresh()->currentRound()->tally['escalate'])->toBe(['moic@moic-suite']);
});

test('the extension is granted once: a third tally past the extension does not extend again', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $change = propose();
    pastDeadline($change);
    ledger()->tally();
    pastDeadline($change);
    ledger()->tally();

    expect(AuditEvent::query()->where('type', 'round.extended')->count())->toBe(1);
});

test('under proceed_and_notify, silence lets the Change proceed and marks the consumer for catch-up', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism'], 'proceed_and_notify');
    $change = propose();

    pastDeadline($change);
    ledger()->tally();
    pastDeadline($change);
    ledger()->tally();

    $petition = petitionFor($change, 'moic@moic-suite');
    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($petition->outcome)->toBe('silent')
        ->and($petition->catch_up)->toBeTrue()
        ->and(AuditEvent::query()->where('type', 'petition.catch_up')->count())->toBe(1);
});

test('a catching-up consumer\'s late vote is still recorded, and a late conflict is flagged for the owner', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism'], 'proceed_and_notify');
    $change = propose();
    pastDeadline($change);
    ledger()->tally();
    pastDeadline($change);
    ledger()->tally();

    $result = vote($change, 'moic@moic-suite', 'conflict');

    expect($result->status)->toBe('late')
        ->and($result->vote->status)->toBe('late')
        ->and($change->fresh()->late_conflict)->toBeTrue()
        ->and($change->fresh()->state)->toBe(States::APPROVED)
        ->and(AuditEvent::query()->where('type', 'vote.late_conflict')->count())->toBe(1);
});

test('a late vote from a consumer NOT on catch-up is refused as invalid', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism']);
    consumer('weaver@weaver', ['particle-academy/prism'], 'proceed_and_notify');
    $change = propose();
    vote($change, 'moic@moic-suite');
    vote($change, 'weaver@weaver');
    ledger()->tally();
    expect($change->fresh()->state)->toBe(States::APPROVED);

    $result = vote($change, 'moic@moic-suite', 'conflict');

    expect($result->status)->toBe('invalid')
        ->and($result->vote->problem)->toBe('round_closed');
});

test('one silent escalate consumer escalates even when the others proceed', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism']);
    consumer('weaver@weaver', ['particle-academy/prism'], 'proceed_and_notify');
    $change = propose();

    pastDeadline($change);
    ledger()->tally();
    pastDeadline($change);
    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::ESCALATED)
        ->and($change->fresh()->currentRound()->tally['escalate'])->toBe(['moic@moic-suite'])
        ->and($change->fresh()->currentRound()->tally['catch_up'])->toBe(['weaver@weaver']);
});

test('the owner\'s per-Change override beats the consumer\'s policy, both ways', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $proceed = propose();
    ledger()->setChangeSilencePolicy($proceed, 'moic@moic-suite', 'proceed_and_notify', 'owner@example.com');
    pastDeadline($proceed);
    ledger()->tally();
    pastDeadline($proceed);
    ledger()->tally();
    expect($proceed->fresh()->state)->toBe(States::APPROVED);

    Carbon::setTestNow('2026-11-20 12:00:00');
    consumer('weaver@weaver', ['particle-academy/prism-x'], 'proceed_and_notify');
    $escalate = propose(['title' => 'x', 'packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism-x']]]);
    ledger()->setChangeSilencePolicy($escalate, 'weaver@weaver', 'escalate', 'owner@example.com');
    pastDeadline($escalate);
    ledger()->tally();
    pastDeadline($escalate);
    ledger()->tally();
    expect($escalate->fresh()->state)->toBe(States::ESCALATED);
});

test('the consumer silence policy is owner-set, validated and logged', function (): void {
    consumer('moic@moic-suite', ['particle-academy/prism']);

    $updated = ledger()->setSilencePolicy('moic@moic-suite', 'proceed_and_notify', 'owner@example.com');

    expect($updated->silence_policy)->toBe('proceed_and_notify')
        ->and(Consumer::query()->where('address', 'moic@moic-suite')->value('silence_policy'))->toBe('proceed_and_notify')
        ->and(AuditEvent::query()->where('type', 'consumer.silence_policy')->first()->actor)->toBe('owner:owner@example.com')
        ->and(fn () => ledger()->setSilencePolicy('moic@moic-suite', 'approve', 'owner@example.com'))->toThrow(\ChangeCouncil\Ledger\Refused::class, 'invalid_policy');
});

test('unreachable and undelivered required voters follow the same policy as silence', function (string $result, string $outcome): void {
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $change = propose();
    ledger()->markSent(petitionFor($change, 'moic@moic-suite'), $result);

    pastDeadline($change);
    ledger()->tally();
    pastDeadline($change);
    ledger()->tally();

    expect(petitionFor($change, 'moic@moic-suite')->outcome)->toBe($outcome)
        ->and($change->fresh()->state)->toBe(States::ESCALATED);
})->with([
    'refused send' => ['{"result":{"isError":true,"content":[{"type":"text","text":"No agent matches moic@moic-suite"}]}}', 'undelivered'],
    'permission denied' => ['{"result":{"content":[{"type":"text","text":"Refused.\n{\"ok\":false,\"error\":\"not reachable: permission denied by scope\"}"}]}}', 'unreachable'],
]);
