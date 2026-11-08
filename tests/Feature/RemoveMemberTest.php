<?php

declare(strict_types=1);

use ChangeCouncil\Consumers\ConsumerSeed;
use ChangeCouncil\Facades\Council;
use ChangeCouncil\Laravel\PageRenderer;
use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Members\Members;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Tests\Support\JsonPageRenderer;
use ChangeCouncil\Tests\Support\User;

/*
 * The owner removes a member from the council (2026-11-08: TribeShare). The row
 * is never deleted: its voting record and audit history stay readable. A removed
 * member gets no new petitions, its open petitions are withdrawn (never silent,
 * never counted), it leaves the census check, and re-seeding never brings it
 * back. Removal is an owner method: only Http\Api behind `owner` reaches it.
 */

const REMOVED = 'tribeshare@tribetrip';

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism']);
    consumer('weaver@weaver', ['particle-academy/prism']);
    consumer(REMOVED, ['particle-academy/prism']);
});

function removeMembersApp(Closure $authorize): void
{
    Council::authorizeUsing($authorize);
    app()->instance(PageRenderer::class, new JsonPageRenderer);
    Council::routes(['middleware' => []]);
}

test('removeMember marks the row removed, keeps it, and audits who removed it', function (): void {
    $removed = ledger()->removeMember(REMOVED, 'owner@example.com', 'Owner instruction.');
    $event = AuditEvent::query()->where('type', 'consumer.removed')->first();

    expect($removed->removed_at)->not->toBeNull()
        ->and(Consumer::query()->where('address', REMOVED)->exists())->toBeTrue()
        ->and($event->actor)->toBe('owner:owner@example.com')
        ->and($event->change_id)->toBeNull()
        ->and($event->data)->toMatchArray(['address' => REMOVED, 'withdrawn' => [], 'reason' => 'Owner instruction.'])
        ->and(fn () => ledger()->removeMember(REMOVED, 'owner@example.com'))->toThrow(Refused::class, 'invalid_state')
        ->and(fn () => ledger()->removeMember('nobody@nowhere', 'owner@example.com'))->toThrow(Refused::class, 'not_found')
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('a removed member gets no petitions', function (): void {
    ledger()->removeMember(REMOVED, 'owner@example.com');

    $change = propose();

    expect(Petition::query()->where('change_id', $change->id)->orderBy('agent_address')->pluck('agent_address')->all())
        ->toBe(['moic@moic-suite', 'weaver@weaver']);
});

test('its open petitions are withdrawn and audited, never counted as silent, and cannot escalate the change', function (): void {
    $change = propose();
    $petition = petitionFor($change, REMOVED);
    vote($change, 'moic@moic-suite');
    vote($change, 'weaver@weaver');

    ledger()->removeMember(REMOVED, 'owner@example.com');
    $withdrawn = AuditEvent::query()->where('type', 'petition.withdrawn')->get();

    expect($petition->fresh()->withdrawn_at)->not->toBeNull()
        ->and($petition->fresh()->outcome)->toBe('withdrawn')
        ->and($withdrawn)->toHaveCount(1)
        ->and($withdrawn[0]->change_id)->toBe($change->id)
        ->and($withdrawn[0]->actor)->toBe('owner:owner@example.com')
        ->and($withdrawn[0]->data)->toMatchArray(['petition' => $petition->id, 'to' => REMOVED, 'round' => 1, 'counted' => false])
        ->and(AuditEvent::query()->where('type', 'consumer.removed')->value('data')['withdrawn'])->toBe([$petition->id])
        // The clerk is never handed the withdrawn petition to send.
        ->and(array_column(json_decode(runCommand('council:petitions', ['--json' => true]), true), 'to'))->not->toContain(REMOVED);

    // Both remaining members answered, so the round closes now, without
    // waiting on (or extending for) the removed member.
    expect(ledger()->tallyChange($change->fresh()))->toBe('closed');
    $round = $change->fresh()->currentRound();

    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($round->tally['counts']['silent'])->toBe(0)
        ->and($round->tally['escalate'])->toBe([])
        ->and($petition->fresh()->outcome)->toBe('withdrawn');
});

test('past the deadline, a withdrawn petition is not a silent required voter', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite');
    vote($change, 'weaver@weaver');
    // Without the removal, the round would wait for the third required voter.
    ledger()->removeMember(REMOVED, 'owner@example.com');
    pastDeadline($change);

    expect(ledger()->tallyChange($change->fresh()))->toBe('closed')
        ->and($change->fresh()->state)->toBe(States::APPROVED);
});

test('the tally of an open round ignores a removed member\'s vote, and a reply after removal is refused', function (): void {
    $change = propose();
    vote($change, REMOVED, 'conflict');
    $round = $change->fresh()->currentRound();
    expect(ledger()->openConflicts($change->fresh()))->toHaveCount(1);

    ledger()->removeMember(REMOVED, 'owner@example.com');

    expect(ledger()->openConflicts($change->fresh()))->toBe([])
        ->and(ledger()->weighing($round->fresh())['blocking_weight'])->toBe(0);

    $late = vote($change, REMOVED, 'conflict');
    expect($late->status)->toBe('invalid')
        ->and($late->problem)->toBe('petition_withdrawn');

    vote($change, 'moic@moic-suite');
    vote($change, 'weaver@weaver');
    ledger()->tallyChange($change->fresh());

    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($change->fresh()->currentRound()->tally['counts']['conflict'])->toBe(0)
        ->and($change->fresh()->currentRound()->tally['blocking'])->toBe([]);
});

test('a removed member with no census does not hold a Change in census_stale', function (): void {
    consumer('stale@nowhere', ['particle-academy/other'], extra: ['census_at' => null]);
    ledger()->removeMember('stale@nowhere', 'owner@example.com');

    $change = propose(['packages' => [['ecosystem' => 'composer', 'name' => 'particle-academy/nobody-uses-this']]]);
    ledger()->tallyChange($change);

    expect($change->fresh()->state)->toBe(States::APPROVED)
        ->and($change->fresh()->currentRound()->tally['reason'])->toBe('unopposed');
});

test('the seeder does not resurrect a removed member, and tribeshare is no longer seeded', function (): void {
    Consumer::query()->delete();
    runCommand('council:seed-consumers');

    expect(Consumer::query()->where('address', REMOVED)->exists())->toBeFalse()
        ->and(array_column(ConsumerSeed::rows(), 'address'))->not->toContain(REMOVED);

    $before = Consumer::query()->where('address', 'guardcard@guardcard-net')->first();
    ledger()->removeMember('guardcard@guardcard-net', 'owner@example.com');
    Consumer::query()->where('address', 'guardcard@guardcard-net')->update(['packages' => json_encode([])]);
    $count = Consumer::query()->count();

    runCommand('council:seed-consumers');
    $after = Consumer::query()->where('address', 'guardcard@guardcard-net')->first();

    expect(Consumer::query()->count())->toBe($count)
        ->and($after->removed_at)->not->toBeNull()
        ->and($after->packages)->toBe([])
        ->and($after->id)->toBe($before->id)
        ->and(array_column(app(Members::class)->list(), 'address'))->not->toContain('guardcard@guardcard-net');
});

test('its history is still readable: profile, voting record and audit trail, listed under former members', function (): void {
    $change = propose();
    vote($change, REMOVED, 'abstain');
    vote($change, 'moic@moic-suite');
    vote($change, 'weaver@weaver');
    ledger()->tally();
    $open = propose(['title' => 'second']);

    ledger()->removeMember(REMOVED, 'owner@example.com');
    $members = app(Members::class);
    $row = $members->find(REMOVED);
    $record = $members->record($row);
    $profile = $members->profile($row);

    expect(array_column($members->list(), 'address'))->toBe(['moic@moic-suite', 'weaver@weaver'])
        ->and(array_column($members->former(), 'address'))->toBe([REMOVED])
        ->and($profile['removed_at'])->toBe('2026-10-27T12:00:00Z')
        ->and($profile['stats'])->toMatchArray(['petitions' => 2, 'cast' => 1, 'abstain' => 1, 'missed' => 0, 'pending' => 0, 'withdrawn' => 1])
        ->and(array_map(fn ($r) => [$r['change']['ref'], $r['verdict'], $r['withdrawn']], $record))->toBe([
            [$open->ref, 'withdrawn', true],
            [$change->ref, 'abstain', false],
        ])
        ->and(AuditEvent::query()->where('change_id', $change->id)->where('actor', REMOVED)->exists())->toBeTrue()
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('the API removes a member only behind `owner`; a viewer and a guest are refused and nothing changes', function (): void {
    removeMembersApp(fn (mixed $user, string $ability): bool => $ability === 'view');
    $viewer = User::create(['name' => 'Eve', 'email' => 'eve@example.com']);

    expect(http('POST', '/api/v1/members/'.REMOVED.'/remove', [], $viewer)['status'])->toBe(403)
        ->and(http('POST', '/api/v1/members/'.REMOVED.'/remove', [])['status'])->toBe(403)
        ->and(Consumer::query()->where('address', REMOVED)->value('removed_at'))->toBeNull()
        ->and(AuditEvent::query()->where('type', 'consumer.removed')->count())->toBe(0);
});

test('the owner removes a member over HTTP; the lists split it out', function (): void {
    removeMembersApp(fn (): bool => true);
    $owner = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);

    $removed = http('POST', '/api/v1/members/'.REMOVED.'/remove', ['reason' => 'Owner instruction.'], $owner);
    $list = http('GET', '/api/v1/members', null, $owner)['body'];

    expect($removed['status'])->toBe(200)
        ->and($removed['body']['member']['address'])->toBe(REMOVED)
        ->and($removed['body']['member']['removed_at'])->toBe('2026-10-27T12:00:00Z')
        ->and(AuditEvent::query()->where('type', 'consumer.removed')->value('actor'))->toBe('owner:owner@example.com')
        ->and(array_column($list['members'], 'address'))->toBe(['moic@moic-suite', 'weaver@weaver'])
        ->and(array_column($list['former_members'], 'address'))->toBe([REMOVED])
        ->and(http('GET', '/api/v1/members/'.REMOVED, null, $owner)['status'])->toBe(200)
        ->and(http('POST', '/api/v1/members/'.REMOVED.'/remove', [], $owner)['status'])->toBe(409)
        ->and(http('POST', '/api/v1/members/nobody@nowhere/remove', [], $owner)['status'])->toBe(404)
        ->and(http('POST', '/api/v1/members/moic@moic-suite/remove', ['extra' => 1], $owner)['status'])->toBe(422)
        ->and(http('POST', '/api/v1/members/moic@moic-suite/remove', ['reason' => 7], $owner)['status'])->toBe(422);
});
