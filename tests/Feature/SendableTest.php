<?php

declare(strict_types=1);

use ChangeCouncil\Http\Presenter;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Petition;

/*
 * "Queued" means one thing: a petition the clerk can still send. The index
 * page counted every `delivery = queued` row (27 on a live council) while
 * council:petitions listed none, because a petition left queued when its
 * round closed, its Change was decided or its member was removed can never be
 * sent. One predicate (Petition::sendable) now answers both, and those
 * transitions record the petition as `superseded`, audited.
 */

beforeEach(function (): void {
    councilApp();
    consumer('moic@moic-suite', ['particle-academy/prism'], 'proceed_and_notify');
    consumer('weaver@weaver', ['particle-academy/prism'], 'proceed_and_notify');
});

function queuedOnPage(Change $change): int
{
    return app(Presenter::class)->summary($change->fresh())['petitions']['queued'];
}

/** @return list<int> petition ids council:petitions would hand the clerk */
function clerkList(): array
{
    return array_column(json_decode(runCommand('council:petitions', ['--json' => true]), true), 'petition');
}

function supersededEvents(): array
{
    return AuditEvent::query()->where('type', 'petition.superseded')->orderBy('seq')->get()->map(fn (AuditEvent $e) => $e->data)->all();
}

test('an open round: the page and the clerk agree on what is queued', function (): void {
    $change = propose();

    expect(queuedOnPage($change))->toBe(2)
        ->and(clerkList())->toHaveCount(2)
        ->and(Petition::query()->sendable()->pluck('id')->all())->toBe(clerkList());
});

test('an owner override supersedes the round\'s unsent petitions, audited, and the page stops counting them', function (): void {
    $change = propose();
    ledger()->override($change, 'owner@example.com', 'approve', 'Security fix; vote skipped.');

    expect(queuedOnPage($change))->toBe(0)
        ->and(clerkList())->toBe([])
        ->and(Petition::query()->where('change_id', $change->id)->pluck('delivery')->unique()->values()->all())->toBe(['superseded'])
        ->and(supersededEvents())->toHaveCount(2)
        ->and(supersededEvents()[0]['because'])->toBe('change_decided')
        ->and(AuditEvent::query()->where('type', 'petition.superseded')->value('actor'))->toBe('owner:owner@example.com')
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('a round closed by the tally supersedes its unsent petitions; a sent one keeps its delivery', function (): void {
    $change = propose();
    $sent = petitionFor($change, 'moic@moic-suite');
    ledger()->markSent($sent, json_encode(['ok' => true, 'id' => 'msg-1']));
    pastDeadline($change);
    ledger()->tally();
    pastDeadline($change);
    ledger()->tally();

    expect($change->fresh()->currentRound()->closed_at)->not->toBeNull()
        ->and(queuedOnPage($change))->toBe(0)
        ->and($sent->fresh()->delivery)->toBe('sent')
        ->and(petitionFor($change, 'weaver@weaver')->delivery)->toBe('superseded')
        ->and(array_column(supersededEvents(), 'because'))->toBe(['round_closed']);
});

test('an answered petition the clerk never marked sent is not superseded: the reply proves it arrived', function (): void {
    $change = propose();
    vote($change, 'moic@moic-suite');
    ledger()->override($change, 'owner@example.com', 'approve', 'Ship it.');

    expect(petitionFor($change, 'moic@moic-suite')->delivery)->toBe('queued')
        ->and(petitionFor($change, 'weaver@weaver')->delivery)->toBe('superseded')
        ->and(queuedOnPage($change))->toBe(0)
        ->and(ledger()->supersedeStale())->toBe([]);
});

test('a withdrawn Change supersedes its unsent petitions', function (): void {
    $change = propose();
    ledger()->withdraw($change, 'fancy@fancy-agi');

    expect(queuedOnPage($change))->toBe(0)
        ->and(array_column(supersededEvents(), 'because'))->toBe(['change_withdrawn', 'change_withdrawn']);
});

test('a removed member\'s unsent petition is superseded; the others stay queued and sendable', function (): void {
    $change = propose();
    ledger()->removeMember('weaver@weaver', 'owner@example.com');

    $weaver = petitionFor($change, 'weaver@weaver');
    expect($weaver->delivery)->toBe('superseded')
        ->and(petitionFor($change, 'moic@moic-suite')->delivery)->toBe('queued')
        ->and(queuedOnPage($change))->toBe(1)
        ->and(clerkList())->toBe([petitionFor($change, 'moic@moic-suite')->id])
        ->and(array_column(supersededEvents(), 'because'))->toBe(['member_removed']);
});

test('the page counts only sendable petitions, even for a row left queued before this fix', function (): void {
    $change = propose();
    ledger()->override($change, 'owner@example.com', 'reject', 'No.');
    // A row as an older release left it: decided, round closed, still `queued`.
    Petition::query()->where('change_id', $change->id)->update(['delivery' => 'queued']);

    expect(queuedOnPage($change))->toBe(0)
        ->and(clerkList())->toBe([]);
});

test('council:petitions --supersede-stale supersedes rows left queued, appends to the audit log, and is idempotent', function (): void {
    $open = propose();
    $decided = propose(['title' => 'second change']);
    ledger()->override($decided, 'owner@example.com', 'reject', 'No.');
    Petition::query()->where('change_id', $decided->id)->update(['delivery' => 'queued']);
    $before = AuditEvent::query()->count();
    $rows = Petition::query()->count();

    $out = runCommand('council:petitions', ['--supersede-stale' => true]);

    expect($out)->toContain('2 stale petition(s) superseded')
        ->and(Petition::query()->where('change_id', $decided->id)->pluck('delivery')->unique()->values()->all())->toBe(['superseded'])
        ->and(Petition::query()->where('change_id', $open->id)->pluck('delivery')->unique()->values()->all())->toBe(['queued'])
        ->and(AuditEvent::query()->count())->toBe($before + 2)
        ->and(AuditEvent::query()->orderByDesc('seq')->value('data')['because'])->toBe('not_sendable')
        ->and(Petition::query()->count())->toBe($rows);

    $again = runCommand('council:petitions', ['--supersede-stale' => true]);

    expect($again)->toContain('0 stale petition(s) superseded')
        ->and(AuditEvent::query()->count())->toBe($before + 2)
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('nothing outside Petition::sendable decides what is queued', function (): void {
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Census')) {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (preg_match("/where\\('delivery',\\s*'queued'\\)/", $src) === 1 && ! str_ends_with($file->getPathname(), 'Petition.php')) {
            $hits[] = $file->getFilename();
        }
    }

    expect($hits)->toBe([]);
});
