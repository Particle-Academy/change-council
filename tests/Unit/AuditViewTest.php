<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
 * The Audit tab's words come from parts/audit.ts, a pure helper. PHP cannot run
 * it, so this runs the real file under Node (CI has Node 22 before this step)
 * and asserts on its answers. A missing Node FAILS: a skipped check here would
 * read as a tab nobody tested.
 */

/** @param list<array<string, mixed>> $events */
function auditHelper(array $events): array
{
    $node = (new ExecutableFinder)->find('node');
    expect($node)->not->toBeNull('node is required to run parts/audit.ts');

    $process = new Process([$node, '--experimental-strip-types', '--no-warnings', dirname(__DIR__).'/Support/audit-view.mjs']);
    $process->setInput(json_encode($events, JSON_THROW_ON_ERROR));
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

function auditEvent(string $type, string $actor, array $data, int $seq = 1): array
{
    return ['seq' => $seq, 'at' => '2026-11-22T12:00:00Z', 'actor' => $actor, 'type' => $type, 'data' => $data, 'hash' => str_repeat('ab', 32)];
}

/** A step-up record with the shape the lab's passkey host writes. */
function stepUpData(array $evidence = []): array
{
    return [
        'action' => 'approve-initiation',
        'ref' => 'CC-fancy-0001',
        'address' => null,
        'digest' => 'sha256:'.str_repeat('d', 64),
        'evidence' => [
            'method' => 'passkey',
            'scheme' => 'council-step-up-v1',
            'rp_id' => 'lab.example.test',
            'origin' => 'https://lab.example.test',
            'credential_id' => 'CREDENTIALIDVALUE',
            'credential_public_key' => 'PUBLICKEYVALUE',
            'sign_count' => 4,
            'user_present' => true,
            'user_verified' => true,
            'challenge' => 'CHALLENGEVALUE',
            'bound' => ['context' => ['digest' => 'sha256:bound']],
            'assertion' => [
                'clientDataJSON' => 'CLIENTDATAVALUE',
                'authenticatorData' => 'AUTHDATAVALUE',
                'signature' => 'SIGNATUREVALUE',
                'userHandle' => 'USERHANDLEVALUE',
            ],
            'verified_at' => '2026-11-22T12:00:00Z',
            'verified_by' => 'particle-academy/fancy-passkeys 1.0.0',
            ...$evidence,
        ],
    ];
}

const AUDIT_SECRETS = ['CREDENTIALIDVALUE', 'PUBLICKEYVALUE', 'CHALLENGEVALUE', 'CLIENTDATAVALUE', 'AUTHDATAVALUE', 'SIGNATUREVALUE', 'USERHANDLEVALUE'];

/** Every event type src/ writes: [type, actor, data, title, group, status]. */
function auditCases(): array
{
    $owner = 'owner:owner@example.test';

    return [
        ['change.proposed', 'fancy@fancy', ['ref' => 'CC-1', 'source' => 'agent', 'whitelisted' => false, 'hitl' => true, 'classes' => ['api'], 'packages' => ['npm:react-fancy']], 'Change proposed by fancy', 'system', 'proposed'],
        ['initiation.approved', $owner, ['note' => 'fine'], 'Owner approved the initiation', 'decision', 'approved'],
        ['initiation.rejected', $owner, ['reason' => 'no'], 'Owner rejected the initiation', 'decision', 'rejected'],
        ['round.opened', 'fancy@fancy', ['round' => 1, 'digest' => 'sha256:aa', 'closes_at' => '2026-11-25T12:00:00Z'], 'Round 1 opened', 'system', 'open'],
        ['round.extended', 'tally', ['round' => 1, 'until' => '2026-11-26T12:00:00Z', 'silent' => ['moic@moic']], 'Round 1 extended', 'system', 'extended'],
        ['round.closed', 'tally', ['round' => 1, 'outcome' => 'approved', 'reason' => 'all_approved', 'counts' => ['approve' => 2, 'conflict' => 0], 'blocking_weight' => 0, 'threshold' => 1], 'Round 1 closed: approved', 'vote', 'approved'],
        ['approach.revised', 'fancy@fancy', ['round' => 2, 'digest' => 'sha256:bb'], 'Approach revised by fancy', 'system', 'revised'],
        ['petition.queued', 'fancy@fancy', ['petition' => 3, 'to' => 'moic@moic', 'tier' => 'required', 'round' => 1], 'Petition queued for moic', 'system', 'queued'],
        ['petition.superseded', 'tally', ['petition' => 3, 'to' => 'moic@moic', 'round' => 1, 'because' => 'round_closed'], 'Petition to moic superseded', 'system', 'superseded'],
        ['petition.catch_up', 'tally', ['petition' => 3, 'to' => 'moic@moic', 'outcome' => 'silent'], 'Catch-up petition for moic', 'system', 'silent'],
        ['petition.withdrawn', $owner, ['petition' => 3, 'to' => 'moic@moic', 'round' => 1, 'tier' => 'required', 'because' => 'member_removed', 'counted' => false], 'Petition to moic withdrawn', 'system', 'withdrawn'],
        ['vote.recorded', 'moic@moic', ['petition' => 3, 'vote' => 7, 'round' => 1, 'verdict' => 'approve', 'worst' => null, 'flags' => 0], 'Vote: approve from moic', 'vote', 'approve'],
        ['vote.late', 'moic@moic', ['petition' => 3, 'vote' => 7, 'verdict' => 'conflict'], 'Late vote: conflict from moic', 'vote', 'conflict'],
        ['vote.late_conflict', 'ledger', ['petition' => 3, 'vote' => 7, 'severity' => 'blocker'], 'Late conflict on vote #7', 'vote', 'blocker'],
        ['change.approved', 'tally', ['reason' => null], 'Approved by the tally', 'decision', 'approved'],
        ['change.rejected', $owner, ['reason' => 'breaks consumers'], 'Rejected by the owner', 'decision', 'rejected'],
        ['change.revoked', $owner, ['reason' => 'found a defect'], 'Approval revoked by the owner', 'decision', 'revoked'],
        ['change.escalated', 'tally', ['reason' => 'blocking_conflict'], 'Escalated to the owner', 'decision', 'escalated'],
        ['change.revision_required', 'tally', ['reason' => 'conflict'], 'Revision required', 'decision', 'revision required'],
        ['change.withdrawn', 'fancy@fancy', [], 'Withdrawn by fancy', 'decision', 'withdrawn'],
        ['owner.override', $owner, ['decision' => 'approve', 'reason' => 'accepted', 'from_state' => 'escalated', 'acknowledged' => [7]], 'Owner override: approve', 'decision', 'approve'],
        ['owner.step_up', $owner, stepUpData(), 'Owner approved with passkey', 'decision', 'verified'],
        ['owner.step_up_refused', $owner, ['action' => 'override', 'ref' => 'CC-1', 'address' => null, 'digest' => 'sha256:aa', 'reason' => 'passkey_invalid', 'evidence' => []], 'Owner step-up refused', 'decision', 'refused'],
        ['notify.sent', 'notifier', ['event' => 'awaiting_owner', 'ref' => 'CC-1', 'status' => 200], 'Owner notified', 'system', 'sent'],
        ['notify.skipped', 'notifier', ['event' => 'awaiting_owner', 'ref' => 'CC-1', 'status' => 'env_missing'], 'Owner notice skipped', 'system', 'skipped'],
        ['notify.failed', 'notifier', ['event' => 'awaiting_owner', 'ref' => 'CC-1', 'status' => 429, 'retry_after' => 30], 'Owner notice failed', 'system', 'failed'],
        ['change.silence_policy', $owner, ['address' => 'moic@moic', 'policy' => 'escalate'], 'Silence policy set for moic on this Change', 'decision', 'escalate'],
        ['consumer.silence_policy', $owner, ['address' => 'moic@moic', 'from' => 'escalate', 'to' => 'proceed_and_notify'], 'Silence policy changed for moic', 'decision', 'proceed and notify'],
        ['consumer.weight', $owner, ['address' => 'moic@moic', 'from' => 1, 'to' => 2], 'Weight changed for moic', 'decision', 'weight 2'],
        ['consumer.project', $owner, ['address' => 'moic@moic', 'fields' => ['project_name']], 'Project details changed for moic', 'decision', 'updated'],
        ['consumer.invited', $owner, ['address' => 'moic@moic', 'weight' => 1, 'silence_policy' => 'escalate', 'fields' => []], 'moic invited to the council', 'decision', 'invited'],
        ['consumer.removed', $owner, ['address' => 'moic@moic', 'withdrawn' => [3], 'reason' => 'left'], 'moic removed from the council', 'decision', 'removed'],
        ['council.blocking_threshold', $owner, ['from' => 1, 'to' => 2], 'Blocking threshold changed', 'decision', 'threshold 2'],
        ['census.requested', 'clerk', ['request' => 5, 'to' => 'moic@moic'], 'Census requested from moic', 'system', 'requested'],
        ['census.applied', 'moic@moic', ['address' => 'moic@moic', 'request' => 5, 'reply' => 9, 'before' => [], 'after' => ['source' => 'census'], 'added' => ['npm:react-fancy'], 'removed' => [], 'changed' => [], 'dropped' => [], 'apps' => 2, 'flags' => 0], 'Census applied from moic', 'system', 'applied'],
    ];
}

test('every event type gets its human title, group and status', function (): void {
    $cases = auditCases();
    $events = array_map(fn (array $c, int $i): array => auditEvent($c[0], $c[1], $c[2], $i + 1), $cases, array_keys($cases));
    $views = auditHelper($events)['views'];

    foreach ($cases as $i => $case) {
        expect($views[$i]['title'])->toBe($case[3], $case[0])
            ->and($views[$i]['group'])->toBe($case[4], $case[0])
            ->and($views[$i]['status'])->toBe($case[5], $case[0])
            ->and($views[$i]['summary'])->toBeString()->not->toBe('', $case[0])
            ->and($views[$i]['facts'])->toBeArray()->not->toBeEmpty($case[0]);
    }
});

/*
 * The list above is typed by hand, so it could fall behind the ledger. This
 * reads the types out of src/ (the literal ones, the `change.` + state ones from
 * decide(), and the two the tally picks between) and requires the helper and
 * the cases to know every one.
 */
test('the helper and these cases cover every audit type src/ can write', function (): void {
    $root = dirname(__DIR__, 2).'/src';
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        preg_match_all("/->append\\([^;]*?'([a-z_]+\\.[a-z_]+)'/s", $source, $appended);
        preg_match_all("/->record\\(\\\$event, '([a-z_]+\\.[a-z_]+)'/", $source, $recorded);
        $found = [...$found, ...$appended[1], ...$recorded[1]];
    }
    $found = [...$found, 'change.approved', 'change.rejected', 'change.revoked', 'change.revision_required', 'change.escalated'];
    $found = array_values(array_unique($found));
    sort($found);

    $known = auditHelper([])['types'];
    $cased = array_column(auditCases(), 0);

    expect(count($found))->toBeGreaterThanOrEqual(35)
        ->and(array_values(array_diff($found, $known)))->toBe([])
        ->and(array_values(array_diff($found, $cased)))->toBe([]);
});

test('a step-up row names the facts the owner reads, and no credential material', function (): void {
    $view = auditHelper([auditEvent('owner.step_up', 'owner:owner@example.test', stepUpData())])['views'][0];
    $facts = array_column($view['facts'], 'value', 'label');

    expect($facts)->toMatchArray([
        'Method' => 'passkey',
        'Action' => 'approve initiation',
        'Verified at' => '2026-11-22T12:00:00Z',
        'Verified by' => 'particle-academy/fancy-passkeys 1.0.0',
        'User present' => 'yes',
        'User verified' => 'yes',
    ]);

    $shown = json_encode([$view['title'], $view['summary'], $view['status'], $view['facts']], JSON_THROW_ON_ERROR);
    foreach (AUDIT_SECRETS as $secret) {
        expect($shown)->not->toContain($secret);
    }
});

test('a step-up flag that was not recorded reads "not recorded", never "no"', function (): void {
    $data = stepUpData();
    unset($data['evidence']['user_present'], $data['evidence']['user_verified']);
    $facts = array_column(auditHelper([auditEvent('owner.step_up', 'owner:o@example.test', $data)])['views'][0]['facts'], 'value', 'label');

    expect($facts['User present'])->toBe('not recorded')
        ->and($facts['User verified'])->toBe('not recorded');
});

test('a step-up without user verification does not get the all-clear tone', function (): void {
    $good = auditHelper([auditEvent('owner.step_up', 'owner:o@example.test', stepUpData())])['views'][0];
    $weak = auditHelper([auditEvent('owner.step_up', 'owner:o@example.test', stepUpData(['user_verified' => false]))])['views'][0];

    expect($good['tone'])->toBe('green')
        ->and($weak['tone'])->toBe('amber')
        ->and($weak['summary'])->toContain('without user verification');
});

test('the raw record is the whole event, unchanged', function (): void {
    $event = auditEvent('owner.step_up', 'owner:o@example.test', stepUpData());
    $raw = auditHelper([$event])['raw'][0];

    expect(json_decode($raw, true, 512, JSON_THROW_ON_ERROR))->toBe($event);
    foreach (AUDIT_SECRETS as $secret) {
        expect($raw)->toContain($secret);
    }
});

test('an unknown type gets a generic summary from its scalars, and never shows a secret-named or opaque value', function (): void {
    $view = auditHelper([auditEvent('future.thing_happened', 'someone@somewhere', [
        'note' => 'plain words',
        'count' => 3,
        'enabled' => true,
        'signature' => 'SIGNATUREVALUE',
        'clientDataJSON' => 'CLIENTDATAVALUE',
        'opaque' => str_repeat('Qk9H', 20),
        'nested' => ['signature' => 'SIGNATUREVALUE'],
    ])])['views'][0];

    $shown = json_encode([$view['title'], $view['summary'], $view['facts']], JSON_THROW_ON_ERROR);

    expect($view['title'])->toBe('Future thing happened')
        ->and($view['group'])->toBe('system')
        ->and($view['summary'])->toContain('plain words')
        ->and($shown)->not->toContain('SIGNATUREVALUE')
        ->and($shown)->not->toContain('CLIENTDATAVALUE')
        ->and($shown)->not->toContain('Qk9HQk9H');
});

test('malformed data never crashes the helper', function (mixed $data): void {
    $event = auditEvent('owner.step_up', 'owner:o@example.test', []);
    $event['data'] = $data;
    $unknown = [...$event, 'type' => 'constructor', 'seq' => 2];
    $proto = [...$event, 'type' => '__proto__', 'seq' => 3];

    $views = auditHelper([$event, $unknown, $proto])['views'];

    expect($views)->toHaveCount(3)
        ->and($views[0]['title'])->toBe('Owner confirmed an action with step-up')
        ->and($views[1]['group'])->toBe('system')
        ->and($views[2]['group'])->toBe('system');
})->with([
    'empty list' => [[]],
    'null' => [null],
    'a string' => ['nope'],
    'wrong field types' => [['action' => 5, 'evidence' => 'nope', 'ref' => ['x']]],
]);

test('a free-text reason is quoted as written, and a reason code reads as words', function (): void {
    $views = auditHelper([
        auditEvent('change.rejected', 'owner:o@example.test', ['reason' => 'breaks snake_case keys']),
        auditEvent('change.escalated', 'tally', ['reason' => 'blocking_conflict']),
    ])['views'];

    expect($views[0]['summary'])->toContain('breaks snake_case keys')
        ->and($views[1]['summary'])->toContain('blocking conflict');
});

test('the filter splits decisions, votes and system events, and All keeps every event', function (): void {
    $events = [
        auditEvent('owner.step_up', 'owner:o@example.test', stepUpData(), 1),
        auditEvent('vote.recorded', 'moic@moic', ['verdict' => 'approve'], 2),
        auditEvent('notify.sent', 'notifier', ['status' => 200], 3),
        auditEvent('future.thing', 'x', [], 4),
    ];

    expect(auditHelper($events)['filters'])->toBe([
        'all' => [1, 2, 3, 4],
        'decision' => [1],
        'vote' => [2],
        'system' => [3, 4],
    ]);
});

test('routine notices are quiet, and a failed notice is not', function (): void {
    $views = auditHelper([
        auditEvent('notify.sent', 'notifier', ['status' => 200]),
        auditEvent('notify.skipped', 'notifier', ['status' => 'env_missing']),
        auditEvent('notify.failed', 'notifier', ['status' => 500]),
    ])['views'];

    expect($views[0]['quiet'])->toBeTrue()
        ->and($views[1]['quiet'])->toBeTrue()
        ->and($views[2]['quiet'])->toBeFalse()
        ->and($views[2]['tone'])->toBe('red');
});

/*
 * PHP cannot render the page, so these hold the source to the structure that
 * keeps credential material out of the default row: the details and the raw
 * record are mounted only once opened, each behind its own control.
 */
test('the row mounts its details and the raw record only when opened', function (): void {
    $show = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Council/Show.tsx');

    expect($show)->not->toContain('JSON.stringify(e.data)')
        ->and($show)->toContain('describeAuditEvent(')
        ->and($show)->toMatch('/useState\(false\)[\s\S]*?data-action="audit-toggle-details"/')
        ->and($show)->toMatch('/\{open && \(\s*<div[^>]*data-audit-details/')
        ->and($show)->toMatch('/\{rawOpen && \(\s*<div[^>]*data-audit-raw/')
        ->and($show)->toMatch('/<Button data-action="audit-toggle-details"[^>]*aria-expanded=\{open\}/s')
        ->and($show)->toMatch('/<Button data-action="audit-toggle-raw"[^>]*aria-expanded=\{rawOpen\}/s')
        ->and($show)->toContain('data-action="audit-copy-raw"')
        ->and($show)->toContain('data-audit-row={event.seq}')
        ->and($show)->toContain('data-audit-group={view.group}')
        ->and($show)->toMatch('/data-action=\{`audit-filter-\$\{/')
        ->and($show)->toContain('aria-pressed=');

    // A bare Table.Cell has no dark text colour: a fact's value was black on
    // near-black in dark mode. Text carries the colour for both themes.
    expect($show)->toMatch('/<Table\.Cell[^>]*>\s*<Text as="span" size="sm"[^>]*>\{f\.value\}<\/Text>/');

    // The raw JSON is built in one place, inside the opened branch.
    expect(substr_count($show, 'rawAuditRecord('))->toBe(1);
    expect(strpos($show, 'rawAuditRecord('))->toBeGreaterThan(strpos($show, '{rawOpen && ('));
});
