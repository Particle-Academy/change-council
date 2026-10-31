<?php

declare(strict_types=1);

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Notify\GenieInboxNotifier;
use ChangeCouncil\Notify\NullNotifier;
use ChangeCouncil\Notify\OwnerEvent;
use ChangeCouncil\Notify\OwnerEventNotifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/*
 * The owner-event notifier: an optional hook fired AFTER COMMIT on the
 * transitions an owner cares about, and a Genie app-inbox implementation of it
 * (.ai/plans/council-app-inbox.md in the envelope). A notice is a pointer to
 * the lab, never the content: refs, states and counts, no free text. A
 * failure is an audit event, never an exception, and never a rollback.
 */

const NOTIFY_TOKEN = 'SENTINEL-tok-7f3a9c41';
const NOTIFY_URL = 'http://127.0.0.1:45551';

final class NotifyRecorder implements OwnerEventNotifier
{
    /** @var list<array{event: string, ref: string, state: string, level: int, db_state: ?string}> */
    public array $events = [];

    public function notify(OwnerEvent $event): void
    {
        $this->events[] = [
            'event' => $event->event,
            'ref' => $event->ref,
            'state' => $event->state,
            'level' => DB::transactionLevel(),
            'db_state' => Change::query()->where('ref', $event->ref)->value('state'),
        ];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->events, 'event');
    }
}

function recorderApp(): NotifyRecorder
{
    councilApp();
    $recorder = new NotifyRecorder;
    Council::notifyUsing($recorder);

    return $recorder;
}

/** A non-whitelisted initiation: lands in awaiting_owner. */
function hitlChange(): Change
{
    return propose(['initiator' => 'stranger@elsewhere', 'pros' => 'p', 'cons' => 'c']);
}

/** @param array<string, mixed> $config */
function genieApp(mixed $response, array $config = []): Factory
{
    councilApp(['council.notify.genie' => true, 'council.notify.to' => 'fancy@fancy-agi', ...$config]);
    $http = new Factory;
    $http->fake(['*' => $response]);
    app()->instance(Factory::class, $http);
    putenv('GENIE_INBOX_URL='.NOTIFY_URL);
    putenv('GENIE_INBOX_TOKEN='.NOTIFY_TOKEN);

    return $http;
}

/** @return list<Request> every request the fake received, in order */
function sentRequests(Factory $http): array
{
    return $http->recorded()->map(fn (array $pair) => $pair[0])->values()->all();
}

/** @return list<array{type: string, data: array}> */
function notifyEvents(): array
{
    return AuditEvent::query()->where('type', 'like', 'notify.%')->orderBy('seq')->get()
        ->map(fn (AuditEvent $e) => ['type' => $e->type, 'data' => $e->data])->all();
}

/** Every raw audit row, as stored. */
function rawAudit(): string
{
    return json_encode(DB::table('council_events')->get()->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

afterEach(function (): void {
    putenv('GENIE_INBOX_URL');
    putenv('GENIE_INBOX_TOKEN');
});

// ------------------------------------------------------------------ the hook

dataset('owner events', [
    'initiation approved' => [function (): void {
        ledger()->approveInitiation(hitlChange(), 'ada', 'looks fine');
    }, ['initiation_approved'], States::ROUND1_OPEN],
    'initiation rejected' => [function (): void {
        ledger()->rejectInitiation(hitlChange(), 'ada', 'not now');
    }, ['initiation_rejected'], States::REJECTED],
    'override approve' => [function (): void {
        ledger()->override(propose(), 'ada', 'approve', 'ship it');
    }, ['override_approve'], States::APPROVED],
    'override reject' => [function (): void {
        ledger()->override(propose(), 'ada', 'reject', 'no');
    }, ['override_reject'], States::REJECTED],
    'revoke' => [function (): void {
        $change = ledger()->override(propose(), 'ada', 'approve', 'ship it');
        ledger()->revoke($change, 'ada', 'changed my mind');
    }, ['override_approve', 'revoked'], States::REVOKED],
    'round tally closed' => [function (): void {
        consumer('moic@moic-suite', ['particle-academy/prism']);
        vote(propose(), 'moic@moic-suite', 'approve');
        ledger()->tally();
    }, ['round1_closed'], States::APPROVED],
    'escalation to the owner' => [function (): void {
        consumer('moic@moic-suite', ['particle-academy/prism']);
        $change = propose();
        pastDeadline($change);
        ledger()->tally(); // extended
        pastDeadline($change);
        ledger()->tally(); // closed, escalated on silence
    }, ['round1_closed', 'escalated'], States::ESCALATED],
]);

test('every owner event fires exactly once, after commit, with the committed state', function (Closure $act, array $expected, string $finalState): void {
    $recorder = recorderApp();

    $act();

    expect($recorder->names())->toBe($expected);
    foreach ($recorder->events as $event) {
        expect($event['level'])->toBe(0, $event['event'].' fired inside a transaction')
            ->and($event['db_state'])->toBe($event['state'], $event['event'].' fired before its state was committed')
            ->and($event['ref'])->toBe('CC-fancy-0001');
    }
    expect(end($recorder->events)['state'])->toBe($finalState);
})->with('owner events');

test('a rolled-back owner decision notifies nobody', function (): void {
    $recorder = recorderApp();
    $change = hitlChange();

    try {
        DB::transaction(function () use ($change): void {
            ledger()->approveInitiation($change, 'ada');
            throw new RuntimeException('host rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($recorder->events)->toBe([])
        ->and($change->fresh()->state)->toBe(States::AWAITING_OWNER);
});

test('a refused transition notifies nobody', function (): void {
    $recorder = recorderApp();
    $change = propose(); // round 1 open, not awaiting the owner

    expect(fn () => ledger()->approveInitiation($change, 'ada'))->toThrow(\ChangeCouncil\Ledger\Refused::class)
        ->and($recorder->events)->toBe([]);
});

test('transitions that are not owner events notify nobody', function (): void {
    $recorder = recorderApp();
    consumer('moic@moic-suite', ['particle-academy/prism']);
    $change = propose();
    ledger()->setChangeSilencePolicy($change, 'moic@moic-suite', 'proceed_and_notify', 'ada');
    ledger()->withdraw($change, 'fancy@fancy-agi');

    expect($recorder->events)->toBe([]);
});

test('the default is a no-op: nothing is bound, nothing is sent, nothing is audited', function (): void {
    councilApp();
    $http = new Factory;
    $http->fake();
    app()->instance(Factory::class, $http);
    putenv('GENIE_INBOX_URL='.NOTIFY_URL);
    putenv('GENIE_INBOX_TOKEN='.NOTIFY_TOKEN);

    $change = ledger()->approveInitiation(hitlChange(), 'ada');

    expect(app(OwnerEventNotifier::class))->toBeInstanceOf(NullNotifier::class)
        ->and($change->state)->toBe(States::ROUND1_OPEN)
        ->and(sentRequests($http))->toBe([])
        ->and(notifyEvents())->toBe([])
        ->and(Council::notifierStatus()['bound'])->toBeFalse();
});

test('a notifier that throws never fails or rolls back the owner decision', function (): void {
    councilApp();
    Council::notifyUsing(function (OwnerEvent $event): void {
        throw new RuntimeException('boom '.NOTIFY_TOKEN);
    });

    $change = ledger()->approveInitiation(hitlChange(), 'ada');

    expect($change->state)->toBe(States::ROUND1_OPEN)
        ->and($change->fresh()->state)->toBe(States::ROUND1_OPEN)
        ->and(notifyEvents())->toBe([['type' => 'notify.failed', 'data' => ['event' => 'initiation_approved', 'ref' => 'CC-fancy-0001', 'status' => 'exception']]])
        ->and(rawAudit())->not->toContain(NOTIFY_TOKEN)
        ->and(app(\ChangeCouncil\Audit\AuditLog::class)->verify()['ok'])->toBeTrue();
});

// ------------------------------------------------------------------ the Genie notifier

test('the config switch binds the Genie notifier', function (): void {
    genieApp(Factory::response(['ok' => true], 201));

    expect(app(OwnerEventNotifier::class))->toBeInstanceOf(GenieInboxNotifier::class)
        ->and(Council::notifierStatus())->toBe(['bound' => true, 'notifier' => 'GenieInboxNotifier', 'env' => ['GENIE_INBOX_URL' => true, 'GENIE_INBOX_TOKEN' => true]]);
});

test('a 201 is recorded as sent, with the contract body', function (): void {
    $http = genieApp(Factory::response(['ok' => true, 'messageId' => 'm1', 'to' => 'fancy@fancy-agi', 'delivery' => 'push'], 201));

    ledger()->approveInitiation(hitlChange(), 'ada');

    $http->assertSentCount(1);
    $http->assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === NOTIFY_URL.'/v1/inbox'
            && $request->method() === 'POST'
            && $request->header('Authorization') === ['Bearer '.NOTIFY_TOKEN]
            && $request->hasHeader('Origin') === false
            && $body['to'] === 'fancy@fancy-agi'
            && $body['subject'] === 'Change Council CC-fancy-0001: initiation approved'
            && $body['level'] === 'success'
            && $body['link'] === 'https://lab.example.test/council/changes/CC-fancy-0001'
            && $body['dedupeKey'] === 'council:CC-fancy-0001:initiation_approved'
            && str_contains($body['body'], 'state: round1_open')
            && strlen($body['body']) <= GenieInboxNotifier::MAX_BODY_BYTES;
    });
    expect(notifyEvents())->toBe([['type' => 'notify.sent', 'data' => ['event' => 'initiation_approved', 'ref' => 'CC-fancy-0001', 'status' => 201]]]);
});

test('to and link are left out when not configured', function (): void {
    $http = genieApp(Factory::response(['ok' => true], 201), ['council.notify.to' => null, 'council.lab_url' => '']);

    ledger()->rejectInitiation(hitlChange(), 'ada', 'no');

    $http->assertSent(fn (Request $request): bool => ! array_key_exists('to', $request->data())
        && ! array_key_exists('link', $request->data())
        && $request->data()['dedupeKey'] === 'council:CC-fancy-0001:initiation_rejected');
});

dataset('refusals', [
    '404 unknown or revoked token' => [fn () => Factory::response(['error' => 'not_found'], 404), ['status' => 404]],
    '429 over a limit' => [fn () => Factory::response(['error' => 'rate_limited'], 429, ['Retry-After' => '10']), ['status' => 429, 'retry_after' => 10]],
    '500' => [fn () => Factory::response('oops', 500), ['status' => 500]],
    'network failure' => [fn () => Factory::failedConnection('cURL error 7: Failed to connect for '.NOTIFY_URL.'/v1/inbox?token='.NOTIFY_TOKEN), ['status' => 0, 'error' => 'network']],
]);

test('a refusal is an audit event, never an exception, and the owner action still succeeds', function (Closure $response, array $recorded): void {
    $http = genieApp($response());

    $change = ledger()->approveInitiation(hitlChange(), 'ada');

    expect($change->state)->toBe(States::ROUND1_OPEN)
        ->and($change->fresh()->state)->toBe(States::ROUND1_OPEN)
        ->and(notifyEvents())->toBe([['type' => 'notify.failed', 'data' => ['event' => 'initiation_approved', 'ref' => 'CC-fancy-0001', ...$recorded]]])
        ->and(count(sentRequests($http)))->toBe(1) // no retry inside the request
        ->and(app(\ChangeCouncil\Audit\AuditLog::class)->verify()['ok'])->toBeTrue();
})->with('refusals');

test('a refusal through the HTTP API still answers 200', function (): void {
    genieApp(Factory::response('oops', 500));
    Council::authorizeUsing(fn ($user, string $ability): bool => $ability === 'owner' || $ability === 'view');
    app()->instance(\ChangeCouncil\Laravel\PageRenderer::class, new \ChangeCouncil\Tests\Support\JsonPageRenderer);
    Council::routes(['middleware' => []]);
    hitlChange();

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'ok'], \ChangeCouncil\Tests\Support\User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(200)
        ->and(Change::query()->value('state'))->toBe(States::ROUND1_OPEN)
        ->and(array_column(notifyEvents(), 'type'))->toBe(['notify.failed']);
});

test('a missing env is a no-op: nothing sent, a skip recorded, the decision stands', function (string $missing): void {
    $http = genieApp(Factory::response(['ok' => true], 201));
    putenv($missing);

    $change = ledger()->approveInitiation(hitlChange(), 'ada');

    expect($change->state)->toBe(States::ROUND1_OPEN)
        ->and(sentRequests($http))->toBe([])
        ->and(notifyEvents())->toBe([['type' => 'notify.skipped', 'data' => ['event' => 'initiation_approved', 'ref' => 'CC-fancy-0001', 'status' => 'env_missing']]])
        ->and(Council::notifierStatus()['env'][$missing])->toBeFalse();
})->with(['GENIE_INBOX_URL', 'GENIE_INBOX_TOKEN']);

test('the token never reaches an audit row, an exception or a log, at any status', function (): void {
    $handler = new TestHandler;
    $statuses = [
        Factory::response(['ok' => true], 201),
        Factory::response([], 404),
        Factory::response([], 429, ['Retry-After' => '10']),
        Factory::response('', 500),
        Factory::failedConnection('cURL error 7: '.NOTIFY_URL.'?token='.NOTIFY_TOKEN.' Bearer '.NOTIFY_TOKEN),
    ];
    $http = genieApp(null);
    $http->fake(['*' => $http->sequence($statuses)]);
    app()->instance('log', new Logger('test', [$handler]));

    $thrown = [];
    foreach (range(1, count($statuses)) as $i) {
        try {
            ledger()->override(propose(['title' => "change {$i}"]), 'ada', 'approve', 'ship it');
        } catch (Throwable $e) {
            $thrown[] = $e->getMessage();
        }
    }

    // The positive control: the sentinel really was in play.
    $http->assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer '.NOTIFY_TOKEN]);
    expect(count(sentRequests($http)))->toBe(5)
        ->and($thrown)->toBe([])
        ->and(array_column(notifyEvents(), 'type'))->toBe(['notify.sent', 'notify.failed', 'notify.failed', 'notify.failed', 'notify.failed'])
        ->and(rawAudit())->not->toContain(NOTIFY_TOKEN)
        ->and(rawAudit())->not->toContain('127.0.0.1:45551')
        ->and(json_encode(array_map(fn ($r) => $r->formatted ?? $r['message'] ?? '', $handler->getRecords())))->not->toContain(NOTIFY_TOKEN)
        ->and(json_encode(app('config')->all()))->not->toContain(NOTIFY_TOKEN);
});

test('the dedupeKey is council:{ref}:{event}, distinct per event', function (): void {
    $http = genieApp(Factory::response(['ok' => true], 201));
    $change = ledger()->override(propose(), 'ada', 'approve', 'ship it');
    ledger()->revoke($change, 'ada', 'no');

    $keys = array_map(fn (Request $r) => $r->data()['dedupeKey'], sentRequests($http));

    expect($keys)->toBe(['council:CC-fancy-0001:override_approve', 'council:CC-fancy-0001:revoked']);
    foreach ($keys as $key) {
        expect($key)->toMatch('/^council:CC-[a-z0-9-]+-\d{4}:[a-z0-9_]+$/');
    }
});

test('a notice carries refs, states and counts, never free text', function (): void {
    $http = genieApp(Factory::response(['ok' => true], 201));
    consumer('moic@moic-suite', ['particle-academy/prism']);
    consumer('prism@prism-agi', ['particle-academy/prism']);
    $change = propose(['title' => 'TITLE-SENTINEL title text']);
    vote($change, 'moic@moic-suite', 'conflict', ['impact' => [['app' => 'moic', 'paths' => ['a.php'], 'severity' => 'blocker', 'note' => 'REPLY-SENTINEL note']], 'alternative' => 'REPLY-SENTINEL alt']);
    vote($change, 'prism@prism-agi', 'abstain', ['reason' => 'REPLY-SENTINEL reason']);
    ledger()->tally(); // round 1 closes on a blocking conflict
    $blocking = ledger()->openConflicts($change->fresh());
    ledger()->override($change->fresh(), 'ada', 'approve', 'OWNER-SENTINEL reason', $blocking);

    $bodies = array_map(fn (Request $r) => $r->data(), sentRequests($http));
    $wire = json_encode($bodies);

    // The positive control: every sentinel really is in the ledger to leak.
    $stored = json_encode([DB::table('council_votes')->get()->all(), DB::table('council_changes')->get()->all(), DB::table('council_events')->get()->all()]);
    expect($stored)->toContain('REPLY-SENTINEL note')->toContain('REPLY-SENTINEL reason')->toContain('OWNER-SENTINEL')->toContain('TITLE-SENTINEL');

    expect(array_column($bodies, 'dedupeKey'))->toBe(['council:CC-fancy-0001:round1_closed', 'council:CC-fancy-0001:override_approve'])
        ->and($bodies[0]['body'])->toContain('counts: approve=0 conflict=1 abstain=1')
        ->and($bodies[0]['body'])->toContain('state: awaiting_revision')
        ->and($wire)->not->toContain('REPLY-SENTINEL')
        ->and($wire)->not->toContain('OWNER-SENTINEL')
        ->and($wire)->not->toContain('TITLE-SENTINEL');
});

test('the body is capped at 8 KB on a UTF-8 boundary', function (): void {
    $capped = GenieInboxNotifier::cap(str_repeat('é', 6000));

    expect(strlen($capped))->toBeLessThanOrEqual(8192)
        ->and(mb_check_encoding($capped, 'UTF-8'))->toBeTrue()
        ->and(GenieInboxNotifier::cap('short'))->toBe('short');
});

test('the URL and token are read from the environment at send time, not at boot', function (): void {
    $http = genieApp(Factory::response(['ok' => true], 201));
    ledger()->override(propose(), 'ada', 'approve', 'ship it');

    putenv('GENIE_INBOX_URL=http://127.0.0.1:45552');
    putenv('GENIE_INBOX_TOKEN=rotated-token-2');
    ledger()->override(propose(['title' => 'second']), 'ada', 'approve', 'ship it');

    $requests = sentRequests($http);
    expect($requests)->toHaveCount(2)
        ->and($requests[0]->url())->toBe(NOTIFY_URL.'/v1/inbox')
        ->and($requests[0]->header('Authorization'))->toBe(['Bearer '.NOTIFY_TOKEN])
        ->and($requests[1]->url())->toBe('http://127.0.0.1:45552/v1/inbox')
        ->and($requests[1]->header('Authorization'))->toBe(['Bearer rotated-token-2'])
        ->and(app('config')->get('council.notify'))->not->toHaveKey('token')
        ->and(app('config')->get('council.notify'))->not->toHaveKey('url');
});

test('council:status shows whether the notifier is bound and the env present, as booleans only', function (): void {
    councilApp();
    [$code, $output] = runCommandWithCode('council:status', ['--notifier' => true, '--json' => true]);
    expect($code)->toBe(0)
        ->and(json_decode($output, true))->toBe(['bound' => false, 'notifier' => 'NullNotifier', 'env' => ['GENIE_INBOX_URL' => false, 'GENIE_INBOX_TOKEN' => false]]);

    genieApp(Factory::response(['ok' => true], 201));
    $text = runCommand('council:status');
    $json = json_decode(runCommand('council:status', ['--notifier' => true, '--json' => true]), true);

    expect($json)->toBe(['bound' => true, 'notifier' => 'GenieInboxNotifier', 'env' => ['GENIE_INBOX_URL' => true, 'GENIE_INBOX_TOKEN' => true]])
        ->and($text)->toContain('notifier: GenieInboxNotifier (bound); GENIE_INBOX_URL present; GENIE_INBOX_TOKEN present')
        ->and($text.json_encode($json))->not->toContain(NOTIFY_TOKEN)
        ->and($text.json_encode($json))->not->toContain('127.0.0.1');
});
