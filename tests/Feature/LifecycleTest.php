<?php

declare(strict_types=1);

use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Decision;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Tests\Support\TestApp;
use Illuminate\Support\Carbon;

/*
 * The state machine, per the design and the 2026-10-27 owner rulings:
 * whitelisted initiations open round 1; everything else waits for the owner
 * (HITL) with a pros/cons summary, and no petition leaves before approval.
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism', 'particle-academy/prism-harness']);
    consumer('weaver@weaver', ['particle-academy/prism-harness']);
    consumer('tynn@tynn', ['@particle-academy/react-fancy']);
});

test('a whitelisted agent finding opens round 1 at once and petitions only the affected consumers', function (): void {
    $change = propose();

    expect($change->state)->toBe(States::ROUND1_OPEN)
        ->and($change->whitelisted)->toBeTrue()
        ->and($change->ref)->toBe('CC-fancy-0001')
        ->and(TestApp::$transport->recipients())->toBe(['moic@moic-suite'])
        ->and(Petition::query()->where('change_id', $change->id)->first()->tier)->toBe('required');
});

test('a non-whitelisted agent request lands in awaiting_owner and sends NO petition', function (): void {
    $change = propose(['source' => 'agent_request', 'initiator' => 'stranger@elsewhere', 'pros' => 'Removes a footgun.', 'cons' => 'Every caller must change.']);

    expect($change->state)->toBe(States::AWAITING_OWNER)
        ->and($change->whitelisted)->toBeFalse()
        ->and($change->current_round)->toBeNull()
        ->and(Petition::query()->count())->toBe(0)
        ->and(TestApp::$transport->queued)->toBe([]);
});

test('a non-whitelisted initiation without a pros/cons summary is refused', function (array $missing): void {
    expect(fn () => propose(['source' => 'agent_request', 'initiator' => 'stranger@elsewhere', 'pros' => 'p', 'cons' => 'c', ...$missing]))
        ->toThrow(Refused::class, 'pros_cons_required');
})->with([
    'no pros' => [['pros' => '']],
    'no cons' => [['cons' => '  ']],
]);

test('a GitHub issue always goes to HITL, even from a whitelisted author', function (): void {
    $change = propose(['source' => 'github_issue', 'initiator' => 'wishborn', 'pros' => 'p', 'cons' => 'c', 'links' => ['issue' => 'https://github.com/Particle-Academy/prism/issues/9']]);

    expect($change->state)->toBe(States::AWAITING_OWNER)
        ->and($change->whitelisted)->toBeTrue()
        ->and(Petition::query()->count())->toBe(0);
});

test('a whitelisted flag in the input is ignored: the ledger decides who is whitelisted', function (): void {
    $change = propose(['source' => 'agent_request', 'initiator' => 'stranger@elsewhere', 'whitelisted' => true, 'pros' => 'p', 'cons' => 'c']);

    expect($change->whitelisted)->toBeFalse()
        ->and($change->state)->toBe(States::AWAITING_OWNER);
});

test('a consumer row marked whitelisted may initiate directly', function (): void {
    consumer('gene@gene', [], extra: ['whitelisted' => true]);

    expect(propose(['source' => 'agent_request', 'initiator' => 'gene@gene'])->state)->toBe(States::ROUND1_OPEN);
});

test('the owner approving a HITL initiation opens round 1 and only then petitions go out', function (): void {
    $change = propose(['source' => 'github_issue', 'initiator' => 'someone', 'pros' => 'p', 'cons' => 'c']);

    $change = ledger()->approveInitiation($change, 'owner@example.com', 'Worth asking.');

    expect($change->state)->toBe(States::ROUND1_OPEN)
        ->and(TestApp::$transport->recipients())->toBe(['moic@moic-suite'])
        ->and(AuditEvent::query()->where('type', 'initiation.approved')->first()->actor)->toBe('owner:owner@example.com');
});

test('the owner rejecting a HITL initiation ends it with no petition sent', function (): void {
    $change = propose(['source' => 'agent_request', 'initiator' => 'stranger@elsewhere', 'pros' => 'p', 'cons' => 'c']);

    $change = ledger()->rejectInitiation($change, 'owner@example.com', 'Not now.');

    expect($change->state)->toBe(States::REJECTED)
        ->and(Petition::query()->count())->toBe(0);
});

test('every required voter approving closes round 1 as approved by tally', function (): void {
    consumer('miapa@maipa', ['particle-academy/prism']);
    $change = propose();
    vote($change, 'moic@moic-suite');
    vote($change, 'miapa@maipa', 'abstain');

    ledger()->tally();

    $change->refresh();
    expect($change->state)->toBe(States::APPROVED)
        ->and($change->decided_by)->toBe('tally')
        ->and($change->approved_unopposed)->toBeFalse()
        ->and(Decision::query()->where('change_id', $change->id)->first()->outcome)->toBe('approved');
});

test('the round does not close early while a required voter has not answered', function (): void {
    consumer('miapa@maipa', ['particle-academy/prism']);
    $change = propose();
    vote($change, 'moic@moic-suite');

    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::ROUND1_OPEN);
});

test('a blocking conflict sends round 1 to revision, and the revised approach opens round 2 with a new digest', function (): void {
    $change = propose();
    $digest1 = $change->currentRound()->approach_digest;
    vote($change, 'moic@moic-suite', 'conflict');

    ledger()->tally();
    expect($change->fresh()->state)->toBe(States::AWAITING_REVISION);

    $change = ledger()->revise($change->fresh(), "Ship behind a config flag defaulting to allow for one minor.\nFlip the default in 0.141.", 'fancy@fancy-agi');

    expect($change->state)->toBe(States::ROUND2_OPEN)
        ->and($change->current_round)->toBe(2)
        ->and($change->currentRound()->approach_digest)->not->toBe($digest1)
        ->and(petitionFor($change, 'moic@moic-suite')->nonce)->not->toBe(Petition::query()->where('change_id', $change->id)->orderBy('id')->first()->nonce);
});

test('round 2 approval ends approved', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict');
    ledger()->tally();
    $change = ledger()->revise($change->fresh(), 'Behind a flag.', 'fancy@fancy-agi');

    vote($change, 'moic@moic-suite');
    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::APPROVED);
});

test('a conflict in round 2 escalates to the owner, who can reject', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict');
    ledger()->tally();
    $change = ledger()->revise($change->fresh(), 'Behind a flag.', 'fancy@fancy-agi');
    vote($change, 'moic@moic-suite', 'conflict');

    ledger()->tally();
    expect($change->fresh()->state)->toBe(States::ESCALATED);

    $change = ledger()->override($change->fresh(), 'owner@example.com', 'reject', 'MOIC cannot take this in either form.');
    expect($change->state)->toBe(States::REJECTED)
        ->and($change->decided_by)->toBe('owner:owner@example.com');
});

test('revising with an identical approach is refused: a round is a new question or nothing', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict');
    ledger()->tally();

    expect(fn () => ledger()->revise($change->fresh(), $change->currentRound()->approach, 'fancy@fancy-agi'))->toThrow(Refused::class, 'approach_unchanged');
});

test('the approach is frozen while a round is open: revise is refused outside awaiting_revision', function (): void {
    $change = propose();

    expect(fn () => ledger()->revise($change, 'Something else.', 'fancy@fancy-agi'))->toThrow(Refused::class, 'invalid_state');
});

test('a minor conflict does not force round 2: approved with notes', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict', ['impact' => [['app' => 'moic', 'paths' => ['docs/x.md'], 'severity' => 'minor', 'note' => 'Update a doc.']]]);

    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($change->fresh()->approved_with_notes)->toBeTrue();
});

test('no required voters and a fresh census: approved_unopposed, flagged', function (): void {
    $change = propose(['packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/nobody-uses-this']]]);

    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($change->fresh()->approved_unopposed)->toBeTrue();
});

test('no required voters and a stale census escalates: an empty room is not a room that agreed', function (): void {
    consumer('old@old', ['particle-academy/elsewhere'], extra: ['census_at' => Carbon::now()->subDays(45)]);
    $change = propose(['packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/nobody-uses-this']]]);

    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::ESCALATED);
});

test('internal consumers are never petitioned', function (): void {
    consumer('px-ui-sandbox', ['particle-academy/prism'], extra: ['internal' => true]);

    propose();

    expect(TestApp::$transport->recipients())->toBe(['moic@moic-suite']);
});

test('watchers are informed: petitioned, but their silence blocks nothing', function (): void {
    consumer('civi@civi', [], extra: ['watches' => [['ecosystem' => 'composer', 'name' => 'particle-academy/prism']]]);
    $change = propose();
    expect(petitionFor($change, 'civi@civi')->tier)->toBe('informed');
    vote($change, 'moic@moic-suite');

    pastDeadline($change);
    ledger()->tally();

    expect($change->fresh()->state)->toBe(States::APPROVED);
});

test('the owner can override at ANY point, a reason is required, and the override is logged', function (string $when): void {
    $change = match ($when) {
        'awaiting_owner' => propose(['source' => 'agent_request', 'initiator' => 'x@y', 'pros' => 'p', 'cons' => 'c']),
        'round1_open' => propose(),
        'escalated' => (function () {
            $c = propose();
            pastDeadline($c);
            ledger()->tally();
            pastDeadline($c);
            ledger()->tally();

            return $c->fresh();
        })(),
    };
    expect($change->state)->toBe($when);
    expect(fn () => ledger()->override($change, 'owner@example.com', 'approve', '   '))->toThrow(Refused::class, 'reason_required');

    $change = ledger()->override($change, 'owner@example.com', 'approve', 'Security fix; consumers informed separately.');

    $event = AuditEvent::query()->where('type', 'owner.override')->first();
    expect($change->state)->toBe(States::APPROVED)
        ->and($event->actor)->toBe('owner:owner@example.com')
        ->and($event->data['reason'])->toBe('Security fix; consumers informed separately.')
        ->and($event->data['from_state'])->toBe($when);
})->with(['awaiting_owner', 'round1_open', 'escalated']);

test('approving over open conflicts requires acknowledging each one', function (): void {
    consumer('miapa@maipa', ['particle-academy/prism']);
    $change = propose();
    $a = vote($change, 'moic@moic-suite', 'conflict')->vote;
    $b = vote($change, 'miapa@maipa', 'conflict')->vote;

    expect(fn () => ledger()->override($change->fresh(), 'owner@example.com', 'approve', 'Ship it.', [$a->id]))
        ->toThrow(Refused::class, 'acknowledge_conflicts');

    $change = ledger()->override($change->fresh(), 'owner@example.com', 'approve', 'Ship it.', [$a->id, $b->id]);
    expect($change->state)->toBe(States::APPROVED)
        ->and(Decision::query()->where('change_id', $change->id)->latest('id')->first()->acknowledged)->toBe([$a->id, $b->id]);
});

test('an approved Change can be revoked by the owner; the initiator can withdraw a live one', function (): void {
    $approved = propose();
    vote($approved, 'moic@moic-suite');
    ledger()->tally();
    $revoked = ledger()->revoke($approved->fresh(), 'owner@example.com', 'Found a second consumer.');

    $live = propose(['title' => 'another']);
    $withdrawn = ledger()->withdraw($live, 'fancy@fancy-agi');

    expect($revoked->state)->toBe(States::REVOKED)
        ->and($withdrawn->state)->toBe(States::WITHDRAWN)
        ->and(fn () => ledger()->withdraw($live->fresh(), 'fancy@fancy-agi'))->toThrow(Refused::class, 'invalid_state')
        ->and(fn () => ledger()->withdraw(propose(['title' => 'third']), 'someone@else'))->toThrow(Refused::class, 'not_initiator');
});

test('every transition is in the audit log, in order', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite');
    ledger()->tally();

    expect(AuditEvent::query()->where('change_id', $change->id)->orderBy('seq')->pluck('type')->all())
        ->toBe(['change.proposed', 'round.opened', 'petition.queued', 'vote.recorded', 'round.closed', 'change.approved']);
});
