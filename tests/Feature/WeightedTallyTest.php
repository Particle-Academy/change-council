<?php

declare(strict_types=1);

use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Round;
use ChangeCouncil\Records\DecisionRecords;

/*
 * Vote weights and the blocking threshold (owner ruling, 2026-11-07): a round's
 * blocking conflicts (blocker or major) block only when the combined weight of
 * the members raising them reaches the council's threshold. Default threshold 1
 * and every weight 1, so one blocking conflict blocks exactly as before. A
 * weight-0 member is advisory: recorded and shown, never blocking. Below the
 * threshold the Change is approved with notes and flagged for the owner.
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism']);
    consumer('weaver@weaver', ['particle-academy/prism']);
    consumer('tynn@tynn', ['particle-academy/prism']);
});

test('defaults: weight 1, threshold 1, and one blocking conflict still forces round 2 exactly as before', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict');
    vote($change, 'weaver@weaver');
    vote($change, 'tynn@tynn');
    ledger()->tally();

    $round = Round::query()->where('change_id', $change->id)->first();
    expect(Consumer::query()->pluck('weight')->unique()->values()->all())->toBe([1])
        ->and(ledger()->blockingThreshold())->toBe(1)
        ->and($change->fresh()->state)->toBe(States::AWAITING_REVISION)
        ->and($round->tally['blocking_weight'])->toBe(1)
        ->and($round->tally['threshold'])->toBe(1)
        ->and($round->tally['blocking'])->toHaveCount(1)
        ->and($round->tally['below_threshold'])->toBe([]);
});

test('conflicts below the threshold do not block: approved with notes, listed, and flagged for the owner', function (): void {
    ledger()->setBlockingThreshold(3, 'owner@example.com');
    $change = propose();
    $a = vote($change, 'moic@moic-suite', 'conflict')->vote;
    $b = vote($change, 'weaver@weaver', 'conflict', ['impact' => [['app' => 'weaver', 'paths' => [], 'severity' => 'major', 'note' => 'n']]])->vote;
    vote($change, 'tynn@tynn');
    ledger()->tally();

    $fresh = $change->fresh();
    $tally = Round::query()->where('change_id', $change->id)->first()->tally;
    expect($fresh->state)->toBe(States::APPROVED)
        ->and($fresh->approved_with_notes)->toBeTrue()
        ->and($fresh->blocking_below_threshold)->toBeTrue()
        ->and($tally['blocking_weight'])->toBe(2)
        ->and($tally['threshold'])->toBe(3)
        ->and($tally['blocking'])->toBe([])
        ->and($tally['below_threshold'])->toBe([$a->id, $b->id])
        ->and($tally['notes'])->toContain($a->id, $b->id)
        ->and(ledger()->openConflicts($fresh))->toBe([]);
});

test('combined weight that reaches the threshold blocks, and every weighted conflict must be acknowledged on override', function (): void {
    ledger()->setBlockingThreshold(3, 'owner@example.com');
    ledger()->setWeight('moic@moic-suite', 2, 'owner@example.com');
    $change = propose();
    $a = vote($change, 'moic@moic-suite', 'conflict')->vote;
    $b = vote($change, 'weaver@weaver', 'conflict')->vote;
    vote($change, 'tynn@tynn');
    ledger()->tally();

    $tally = Round::query()->where('change_id', $change->id)->first()->tally;
    expect($change->fresh()->state)->toBe(States::AWAITING_REVISION)
        ->and($tally['blocking_weight'])->toBe(3)
        ->and($tally['blocking'])->toBe([$a->id, $b->id])
        ->and($tally['weights'])->toBe(['moic@moic-suite' => 2, 'weaver@weaver' => 1])
        ->and(ledger()->openConflicts($change->fresh()))->toBe([$a->id, $b->id])
        ->and(fn () => ledger()->override($change->fresh(), 'owner@example.com', 'approve', 'ship', [$a->id]))->toThrow(Refused::class, 'acknowledge_conflicts');
});

test('a weight-0 member is advisory: their blocker is recorded and shown but never blocks on its own weight', function (): void {
    ledger()->setWeight('moic@moic-suite', 0, 'owner@example.com');
    $change = propose();
    $a = vote($change, 'moic@moic-suite', 'conflict')->vote;
    vote($change, 'weaver@weaver');
    vote($change, 'tynn@tynn');
    ledger()->tally();

    $tally = Round::query()->where('change_id', $change->id)->first()->tally;
    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($change->fresh()->blocking_below_threshold)->toBeTrue()
        ->and($tally['blocking_weight'])->toBe(0)
        ->and($tally['advisory'])->toBe([$a->id])
        ->and($tally['blocking'])->toBe([]);
});

test('round 2: a weighted conflict escalates as before; one below the threshold approves with notes', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict');
    vote($change, 'weaver@weaver');
    vote($change, 'tynn@tynn');
    ledger()->tally();
    $change = ledger()->revise($change->fresh(), "A revised approach.\n", 'fancy@fancy-agi');

    ledger()->setBlockingThreshold(2, 'owner@example.com');
    vote($change, 'moic@moic-suite', 'conflict');
    vote($change, 'weaver@weaver');
    vote($change, 'tynn@tynn');
    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($change->fresh()->approved_with_notes)->toBeTrue();
});

test('an open round is weighed live, so the owner acknowledges only conflicts that would block', function (): void {
    ledger()->setBlockingThreshold(2, 'owner@example.com');
    $change = propose();
    $a = vote($change, 'moic@moic-suite', 'conflict')->vote;

    expect(ledger()->openConflicts($change->fresh()))->toBe([]);

    $b = vote($change, 'weaver@weaver', 'conflict')->vote;
    expect(ledger()->openConflicts($change->fresh()))->toBe([$a->id, $b->id]);
});

test('existing decided changes are never re-tallied: a legacy tally keeps its old meaning whatever the weights become', function (): void {
    $change = propose();
    $a = vote($change, 'moic@moic-suite', 'conflict')->vote;
    vote($change, 'weaver@weaver');
    vote($change, 'tynn@tynn');
    ledger()->tally();
    // Rewrite the closed round's tally into the shape 0.4 wrote: no weights.
    $round = Round::query()->where('change_id', $change->id)->first();
    $legacy = $round->tally;
    unset($legacy['blocking_weight'], $legacy['threshold'], $legacy['weights'], $legacy['below_threshold'], $legacy['advisory']);
    $round->forceFill(['tally' => $legacy])->save();

    ledger()->setWeight('moic@moic-suite', 0, 'owner@example.com');
    ledger()->setBlockingThreshold(5, 'owner@example.com');
    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::AWAITING_REVISION)
        ->and($round->fresh()->tally)->toBe($legacy)
        ->and(ledger()->openConflicts($change->fresh()))->toBe([$a->id]);
});

test('the threshold is owner-set, validated, audited, and defaults to config', function (): void {
    expect(ledger()->blockingThreshold())->toBe(1);

    app('config')->set('council.tally.blocking_threshold', 4);
    expect(ledger()->blockingThreshold())->toBe(4);

    ledger()->setBlockingThreshold(2, 'owner@example.com');
    $event = AuditEvent::query()->where('type', 'council.blocking_threshold')->first();

    expect(ledger()->blockingThreshold())->toBe(2)
        ->and($event->actor)->toBe('owner:owner@example.com')
        ->and($event->data)->toBe(['from' => 4, 'to' => 2])
        ->and(fn () => ledger()->setBlockingThreshold(0, 'owner@example.com'))->toThrow(Refused::class, 'invalid_threshold')
        ->and(fn () => ledger()->setBlockingThreshold('3', 'owner@example.com'))->toThrow(Refused::class, 'invalid_threshold')
        ->and(fn () => ledger()->setBlockingThreshold(1001, 'owner@example.com'))->toThrow(Refused::class, 'invalid_threshold');
});

test('the decision record carries each round\'s blocking weight and threshold', function (): void {
    ledger()->setBlockingThreshold(2, 'owner@example.com');
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict');
    vote($change, 'weaver@weaver');
    vote($change, 'tynn@tynn');
    ledger()->tally();

    $records = app(DecisionRecords::class);
    $record = $records->build(Change::query()->find($change->id));

    expect($record['rounds'][0]['weighing'])->toBe(['blocking_weight' => 1, 'threshold' => 2, 'below_threshold' => 1, 'advisory' => 0])
        ->and($records->verify($record)['ok'])->toBeTrue();
});
