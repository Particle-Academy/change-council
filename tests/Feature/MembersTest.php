<?php

declare(strict_types=1);

use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Members\Members;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Consumer;
use Illuminate\Support\Carbon;

/*
 * Council members are the consumer rows. The owner asked for a members list, a
 * profile per member with its project and voting record, and a vote weight per
 * member. Weight and project fields are owner-set through the ledger and
 * audited, like the silence policy.
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism', 'particle-academy/prism-harness'], extra: ['watches' => [['ecosystem' => 'npm', 'name' => '@particle-academy/react-fancy']]]);
    consumer('weaver@weaver', ['particle-academy/prism']);
    consumer('genie@genie', ['particle-academy/prism'], extra: ['internal' => true]);
});

function members(): Members
{
    return app(Members::class);
}

test('setWeight stores a non-negative integer, audits from and to, and refuses anything else', function (): void {
    $updated = ledger()->setWeight('moic@moic-suite', 3, 'owner@example.com');
    $event = AuditEvent::query()->where('type', 'consumer.weight')->first();

    expect($updated->weight)->toBe(3)
        ->and(Consumer::query()->where('address', 'moic@moic-suite')->value('weight'))->toBe(3)
        ->and($event->actor)->toBe('owner:owner@example.com')
        ->and($event->change_id)->toBeNull()
        ->and($event->data)->toBe(['address' => 'moic@moic-suite', 'from' => 1, 'to' => 3])
        ->and(ledger()->setWeight('moic@moic-suite', 0, 'owner@example.com')->weight)->toBe(0);

    foreach ([-1, 1.5, '2', null, true, 1001] as $bad) {
        expect(fn () => ledger()->setWeight('moic@moic-suite', $bad, 'owner@example.com'))->toThrow(Refused::class, 'invalid_weight');
    }
    expect(fn () => ledger()->setWeight('nobody@nowhere', 1, 'owner@example.com'))->toThrow(Refused::class, 'not_found')
        ->and(AuditEvent::query()->where('type', 'consumer.weight')->count())->toBe(2);
});

test('the weight ceiling is a setting', function (): void {
    app('config')->set('council.weights.max', 5);

    expect(fn () => ledger()->setWeight('moic@moic-suite', 6, 'owner@example.com'))->toThrow(Refused::class, 'invalid_weight')
        ->and(ledger()->setWeight('moic@moic-suite', 5, 'owner@example.com')->weight)->toBe(5);
});

test('project fields are owner-set, trimmed, audited by field name only, and the URL must be http or https', function (): void {
    $updated = ledger()->setProject('moic@moic-suite', [
        'project_name' => '  MOIC  ',
        'project_description' => 'Moves money.',
        'project_url' => 'https://moic.example.com/repo',
    ], 'owner@example.com');
    $event = AuditEvent::query()->where('type', 'consumer.project')->first();

    expect($updated->only(['project_name', 'project_description', 'project_url']))->toBe(['project_name' => 'MOIC', 'project_description' => 'Moves money.', 'project_url' => 'https://moic.example.com/repo'])
        ->and($event->data)->toBe(['address' => 'moic@moic-suite', 'fields' => ['project_name', 'project_description', 'project_url']])
        ->and(ledger()->setProject('moic@moic-suite', ['project_url' => 'http://moic.example.com'], 'o')->project_url)->toBe('http://moic.example.com')
        ->and(ledger()->setProject('moic@moic-suite', ['project_url' => null, 'project_name' => ''], 'o')->only(['project_name', 'project_url']))->toBe(['project_name' => null, 'project_url' => null]);

    foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.com', '//example.com', 'https://', 'not a url', 'HTTPS://ok.example.com x', str_repeat('a', 10)] as $bad) {
        expect(fn () => ledger()->setProject('moic@moic-suite', ['project_url' => $bad], 'o'))->toThrow(Refused::class, 'invalid_url');
    }
    expect(fn () => ledger()->setProject('moic@moic-suite', ['project_name' => str_repeat('x', 201)], 'o'))->toThrow(Refused::class, 'too_long')
        ->and(fn () => ledger()->setProject('moic@moic-suite', ['project_description' => str_repeat('x', 5001)], 'o'))->toThrow(Refused::class, 'too_long')
        ->and(fn () => ledger()->setProject('moic@moic-suite', ['owner' => 'me'], 'o'))->toThrow(Refused::class, 'invalid_request')
        ->and(fn () => ledger()->setProject('moic@moic-suite', ['project_name' => 7], 'o'))->toThrow(Refused::class, 'invalid_request')
        ->and(Consumer::query()->where('address', 'moic@moic-suite')->value('project_url'))->toBeNull();
});

test('the list counts each member\'s votes, verdicts, missed petitions and last vote, from the real ledger', function (): void {
    // Change 1: moic blocks in round 1, approves round 2; weaver approves both.
    $one = propose();
    vote($one, 'moic@moic-suite', 'conflict');
    vote($one, 'weaver@weaver');
    ledger()->tally();
    Carbon::setTestNow('2026-10-28 09:00:00');
    $one = ledger()->revise($one->fresh(), "Revised.\n", 'fancy@fancy-agi');
    vote($one, 'moic@moic-suite');
    vote($one, 'weaver@weaver', 'abstain');
    ledger()->tally();

    // Change 2: moic stays silent past the deadline and its extension.
    Carbon::setTestNow('2026-10-29 10:00:00');
    $two = propose(['title' => 'second']);
    vote($two, 'weaver@weaver');
    pastDeadline($two);
    ledger()->tally();
    pastDeadline($two);
    ledger()->tally();

    $rows = collect(members()->list())->keyBy('address');
    $moic = $rows['moic@moic-suite'];

    expect($rows->keys()->all())->toBe(['genie@genie', 'moic@moic-suite', 'weaver@weaver'])
        ->and($moic['name'])->toBe('moic')
        ->and($moic['weight'])->toBe(1)
        ->and($moic['package_count'])->toBe(2)
        ->and($moic['stats'])->toBe([
            'petitions' => 3, 'cast' => 2, 'approve' => 1, 'conflict' => 1, 'abstain' => 0,
            'missed' => 1, 'pending' => 0, 'last_vote_at' => '2026-10-28T09:00:00Z',
        ])
        ->and($rows['weaver@weaver']['stats'])->toMatchArray(['petitions' => 3, 'cast' => 3, 'approve' => 2, 'abstain' => 1, 'missed' => 0, 'last_vote_at' => '2026-10-29T10:00:00Z'])
        ->and($rows['genie@genie']['stats'])->toMatchArray(['petitions' => 0, 'cast' => 0, 'last_vote_at' => null])
        ->and($rows['genie@genie']['internal'])->toBeTrue();
});

test('the voting record lists every petition newest first, across rounds, with verdict, impact, severity, timestamps and lateness', function (): void {
    $one = propose();
    ledger()->markSent(petitionFor($one, 'moic@moic-suite'), '{"ok":true,"delivered":1}');
    Carbon::setTestNow('2026-10-27 13:00:00');
    vote($one, 'moic@moic-suite', 'conflict', ['reason' => 'Breaks approvals.']);
    vote($one, 'weaver@weaver');
    ledger()->tally();
    Carbon::setTestNow('2026-10-28 09:00:00');
    $one = ledger()->revise($one->fresh(), "Revised.\n", 'fancy@fancy-agi');
    vote($one, 'moic@moic-suite');
    vote($one, 'weaver@weaver');
    ledger()->tally();

    // A second Change: moic on catch-up answers late.
    ledger()->setSilencePolicy('moic@moic-suite', 'proceed_and_notify', 'owner@example.com');
    Carbon::setTestNow('2026-10-29 10:00:00');
    $two = propose(['title' => 'second']);
    vote($two, 'weaver@weaver');
    pastDeadline($two);
    ledger()->tally();
    pastDeadline($two);
    ledger()->tally();
    vote($two, 'moic@moic-suite');

    // A third: moic undelivered, still open.
    Carbon::setTestNow('2026-10-30 10:00:00');
    $three = propose(['title' => 'third']);
    ledger()->markSent(petitionFor($three, 'moic@moic-suite'), '{"result":{"isError":true,"content":[{"type":"text","text":"No agent matches"}]}}');

    $record = members()->record(Consumer::query()->where('address', 'moic@moic-suite')->first());

    expect(array_map(fn ($r) => [$r['change']['ref'], $r['round'], $r['verdict']], $record))->toBe([
        ['CC-fancy-0003', 1, 'undelivered'],
        ['CC-fancy-0002', 1, 'approve'],
        ['CC-fancy-0001', 2, 'approve'],
        ['CC-fancy-0001', 1, 'conflict'],
    ])
        ->and($record[1]['late'])->toBeTrue()
        ->and($record[1]['outcome'])->toBe('silent')
        ->and($record[2]['late'])->toBeFalse()
        ->and($record[3]['change']['title'])->toBe('prism 0.140: approvals fail closed')
        ->and($record[3]['reason'])->toBe('Breaks approvals.')
        ->and($record[3]['impact'][0]['severity'])->toBe('blocker')
        ->and($record[3]['severity'])->toBe('blocker')
        ->and($record[3]['sent_at'])->toBe('2026-10-27T12:00:00Z')
        ->and($record[3]['replied_at'])->toBe('2026-10-27T13:00:00Z')
        ->and($record[0]['replied_at'])->toBeNull()
        ->and($record[0]['severity'])->toBeNull();
});

test('the 0.5 migration keeps every existing row and gives each member weight 1', function (): void {
    $files = glob(dirname(__DIR__, 2).'/database/migrations/*members*.php') ?: [];
    expect($files)->toHaveCount(1);
    $migration = require $files[0];
    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasColumn('council_consumers', 'weight'))->toBeFalse();
    $before = Consumer::query()->orderBy('address')->pluck('address')->all();

    $migration->up();

    expect(Consumer::query()->orderBy('address')->pluck('address')->all())->toBe($before)
        ->and(Consumer::query()->pluck('weight')->unique()->values()->all())->toBe([1])
        ->and(\Illuminate\Support\Facades\Schema::hasColumns('council_consumers', ['project_name', 'project_description', 'project_url']))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('council_changes', 'blocking_below_threshold'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('council_settings'))->toBeTrue();
});

/*
 * A host's own migration that seeds consumers can run BEFORE the 0.5 migration
 * (flabs seeds at 2026_10_29). The model must not name a column that does not
 * exist yet on insert, or every fresh install of that host fails to migrate.
 */
test('a consumer can be created before the 0.5 migration has run', function (): void {
    $files = glob(dirname(__DIR__, 2).'/database/migrations/*members*.php') ?: [];
    (require $files[0])->down();

    $seeded = consumer('early@seed', ['particle-academy/prism']);

    expect($seeded->exists)->toBeTrue()
        ->and(Consumer::query()->where('address', 'early@seed')->exists())->toBeTrue();
});

test('a project URL that reached the row some other way is never handed out unless it is http or https', function (): void {
    Consumer::query()->where('address', 'moic@moic-suite')->update(['project_url' => 'javascript:alert(1)']);

    expect(members()->profile(Consumer::query()->where('address', 'moic@moic-suite')->first())['project_url'])->toBeNull();
});
