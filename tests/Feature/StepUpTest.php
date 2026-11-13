<?php

declare(strict_types=1);

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Laravel\PageRenderer;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Decision;
use ChangeCouncil\StepUp\NoStepUp;
use ChangeCouncil\StepUp\OwnerAction;
use ChangeCouncil\StepUp\StepUp;
use ChangeCouncil\Tests\Support\FakeStepUp;
use ChangeCouncil\Tests\Support\JsonPageRenderer;
use ChangeCouncil\Tests\Support\User;

/*
 * Council::requireStepUpUsing(): a host may demand a step-up proof (a passkey
 * ceremony, a TOTP, anything) before an OWNER action runs, without the council
 * knowing what the proof is. The proof is bound to the action's digest; a
 * missing proof is a 428 carrying the host's challenge, a refused one is a 403
 * plus an `owner.step_up_refused` audit event, and a verified one is recorded
 * as `owner.step_up` in the same transaction as the action itself.
 */

/** @return array{0: User, 1: FakeStepUp} */
function stepUpApp(?FakeStepUp $fake = new FakeStepUp, ?Closure $actor = null): array
{
    councilApp();
    Council::authorizeUsing(fn (): bool => true);
    if ($fake !== null) {
        Council::requireStepUpUsing($fake);
    }
    if ($actor !== null) {
        Council::resolveActorUsing($actor);
    }
    app()->instance(PageRenderer::class, new JsonPageRenderer);
    Council::routes(['middleware' => []]);
    consumer('moic@moic-suite', ['particle-academy/prism']);
    propose(['source' => 'agent_request', 'initiator' => 'stranger@elsewhere', 'pros' => 'p', 'cons' => 'c']);

    return [User::create(['name' => 'Ada', 'email' => 'ada@example.com']), $fake ?? new FakeStepUp(false)];
}

/** @return list<array{type: string, actor: string, change_id: mixed, data: array}> */
function stepUpEvents(): array
{
    return AuditEvent::query()->orderBy('seq')->get()->map(fn (AuditEvent $e): array => ['type' => $e->type, 'actor' => $e->actor, 'change_id' => $e->change_id, 'data' => $e->data])->all();
}

function goodProof(string $action, ?string $ref, ?string $address, array $params): array
{
    return ['token' => 'good', 'digest' => (new OwnerAction($action, $ref, $address, $params))->digest()];
}

// [method, path, body, action, ref, address]
dataset('owner endpoints', [
    'approve initiation' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'ok'], 'approve-initiation', 'CC-fancy-0001', null],
    'reject initiation' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no'], 'reject-initiation', 'CC-fancy-0001', null],
    'override' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/override', ['decision' => 'reject', 'reason' => 'r', 'acknowledge' => []], 'override', 'CC-fancy-0001', null],
    'revoke' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/revoke', ['reason' => 'r'], 'revoke', 'CC-fancy-0001', null],
    'change silence policy' => ['PUT', '/api/v1/changes/CC-fancy-0001/silence-policy', ['address' => 'moic@moic-suite', 'policy' => 'proceed_and_notify'], 'change-silence-policy', 'CC-fancy-0001', null],
    'consumer silence policy' => ['PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify'], 'consumer-silence-policy', null, 'moic@moic-suite'],
    'member weight' => ['PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 2], 'member-weight', null, 'moic@moic-suite'],
    'member project' => ['PUT', '/api/v1/members/moic@moic-suite/project', ['project_name' => 'MOIC'], 'member-project', null, 'moic@moic-suite'],
    'member remove' => ['POST', '/api/v1/members/moic@moic-suite/remove', ['reason' => 'left'], 'member-remove', null, 'moic@moic-suite'],
    'blocking threshold' => ['PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 2], 'blocking-threshold', null, null],
]);

test('the default hook is NoStepUp', function (): void {
    councilApp();

    expect(app(StepUp::class))->toBeInstanceOf(NoStepUp::class);
});

test('with no hook every owner endpoint is unchanged, and a step_up key is accepted and ignored', function (string $method, string $path, array $body): void {
    [$user] = stepUpApp(null);
    $plain = http($method, $path, $body, $user);
    $plainEvents = array_column(stepUpEvents(), 'type');

    [$user] = stepUpApp(null);
    $carrying = http($method, $path, [...$body, 'step_up' => ['token' => 'whatever']], $user);

    expect($carrying['status'])->toBe($plain['status'])
        ->and(array_column(stepUpEvents(), 'type'))->toBe($plainEvents)
        ->and($plainEvents)->not->toContain('owner.step_up');
})->with('owner endpoints');

test('every owner endpoint goes through the gate: no proof is a 428 with the challenge and the action, and nothing is written', function (string $method, string $path, array $body, string $action, ?string $ref, ?string $address): void {
    [$user, $fake] = stepUpApp();
    $before = stepUpEvents();
    $state = ledger()->find('CC-fancy-0001')->state;
    $member = Consumer::query()->where('address', 'moic@moic-suite')->first()->toArray();

    $response = http($method, $path, $body, $user);
    $expected = new OwnerAction($action, $ref, $address, $body);

    expect($response['status'])->toBe(428)
        ->and($response['body']['error']['code'])->toBe('step_up_required')
        ->and($response['body']['error']['step_up'])->toBe(['kind' => 'fake', 'challenge_id' => 'ch-1', 'digest' => $expected->digest()])
        ->and($response['body']['error']['action'])->toBe([...$expected->toArray(), 'digest' => $expected->digest()])
        ->and(stepUpEvents())->toBe($before)
        ->and(ledger()->find('CC-fancy-0001')->state)->toBe($state)
        ->and(Consumer::query()->where('address', 'moic@moic-suite')->first()->toArray())->toBe($member)
        ->and(Decision::query()->count())->toBe(0);
})->with('owner endpoints');

test('every owner endpoint hands a presented proof to verify() for its own action', function (string $method, string $path, array $body, string $action, ?string $ref, ?string $address): void {
    [$user, $fake] = stepUpApp();

    $response = http($method, $path, [...$body, 'step_up' => goodProof($action, $ref, $address, $body)], $user);

    expect($fake->verified())->toBe([$action])
        ->and($response['status'])->not->toBe(428)
        ->and($response['status'])->not->toBe(403);
})->with('owner endpoints');

test('a good proof runs the action, with owner.step_up immediately before its own event, carrying the evidence and the same actor', function (): void {
    [$user] = stepUpApp();
    $body = ['reason' => 'no'];

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', [...$body, 'step_up' => goodProof('reject-initiation', 'CC-fancy-0001', null, $body)], $user);

    $events = stepUpEvents();
    $at = array_search('owner.step_up', array_column($events, 'type'), true);
    $digest = (new OwnerAction('reject-initiation', 'CC-fancy-0001', null, $body))->digest();
    $change = ledger()->find('CC-fancy-0001');

    expect($response['status'])->toBe(200)
        ->and($change->state)->toBe(States::REJECTED)
        ->and($at)->not->toBeFalse()
        ->and($events[$at + 1]['type'])->toBe('initiation.rejected')
        ->and($events[$at]['actor'])->toBe('owner:ada@example.com')
        ->and($events[$at + 1]['actor'])->toBe('owner:ada@example.com')
        ->and($events[$at]['change_id'])->toBe($change->id)
        ->and($events[$at]['data'])->toBe([
            'action' => 'reject-initiation', 'ref' => 'CC-fancy-0001', 'address' => null, 'digest' => $digest,
            'evidence' => ['method' => 'fake', 'credential' => 'cred-1', 'digest' => $digest],
        ])
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('a member action records the step-up with no change and the hook\'s actor', function (): void {
    [$user] = stepUpApp(new FakeStepUp, fn (): string => 'github:wishborn');
    $body = ['weight' => 3];

    $response = http('PUT', '/api/v1/members/moic@moic-suite/weight', [...$body, 'step_up' => goodProof('member-weight', null, 'moic@moic-suite', $body)], $user);

    $events = stepUpEvents();
    $at = array_search('owner.step_up', array_column($events, 'type'), true);

    expect($response['status'])->toBe(200)
        ->and($events[$at + 1]['type'])->toBe('consumer.weight')
        ->and($events[$at]['actor'])->toBe('owner:github:wishborn')
        ->and($events[$at + 1]['actor'])->toBe('owner:github:wishborn')
        ->and($events[$at]['change_id'])->toBeNull()
        ->and($events[$at]['data']['address'])->toBe('moic@moic-suite')
        ->and($events[$at]['data']['ref'])->toBeNull();
});

test('a wrong proof is a 403 step_up_failed, one owner.step_up_refused event, and the action does not run', function (): void {
    [$user] = stepUpApp();
    $before = count(stepUpEvents());

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no', 'step_up' => ['token' => 'bad']], $user);

    $events = stepUpEvents();
    $digest = (new OwnerAction('reject-initiation', 'CC-fancy-0001', null, ['reason' => 'no']))->digest();

    expect($response['status'])->toBe(403)
        ->and($response['body'])->toBe(['error' => ['code' => 'step_up_failed', 'reason' => 'bad_token']])
        ->and(count($events))->toBe($before + 1)
        ->and(end($events)['type'])->toBe('owner.step_up_refused')
        ->and(end($events)['actor'])->toBe('owner:ada@example.com')
        ->and(end($events)['change_id'])->toBe(ledger()->find('CC-fancy-0001')->id)
        ->and(end($events)['data'])->toBe([
            'action' => 'reject-initiation', 'ref' => 'CC-fancy-0001', 'address' => null, 'digest' => $digest,
            'reason' => 'bad_token', 'evidence' => ['presented' => 'string'],
        ])
        ->and(ledger()->find('CC-fancy-0001')->state)->toBe(States::AWAITING_OWNER)
        ->and(Decision::query()->count())->toBe(0)
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('a proof verified for one action is refused for another', function (): void {
    [$user] = stepUpApp();
    $proof = goodProof('reject-initiation', 'CC-fancy-0001', null, ['reason' => 'no']);

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/revoke', ['reason' => 'no', 'step_up' => $proof], $user);

    expect($response['status'])->toBe(403)
        ->and($response['body']['error']['reason'])->toBe('action_mismatch')
        ->and(array_column(stepUpEvents(), 'type'))->not->toContain('owner.step_up');
});

test('params are part of the digest: a proof for one note or weight does not carry another', function (): void {
    [$user] = stepUpApp();

    $note = http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'later', 'step_up' => goodProof('approve-initiation', 'CC-fancy-0001', null, ['note' => 'ok'])], $user);
    $weight = http('PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 9, 'step_up' => goodProof('member-weight', null, 'moic@moic-suite', ['weight' => 2])], $user);
    $a = http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'ok'], $user)['body']['error']['action']['digest'];
    $b = http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'later'], $user)['body']['error']['action']['digest'];

    expect($note['status'])->toBe(403)
        ->and($note['body']['error']['reason'])->toBe('action_mismatch')
        ->and($weight['status'])->toBe(403)
        ->and($weight['body']['error']['reason'])->toBe('action_mismatch')
        ->and(Consumer::query()->where('address', 'moic@moic-suite')->value('weight'))->not->toBe(9)
        ->and($a)->not->toBe($b);
});

test('a verified step-up whose action the ledger refuses leaves no owner.step_up event', function (): void {
    [$user] = stepUpApp();
    $before = stepUpEvents();
    $body = ['reason' => 'r'];

    // revoke needs an approved Change; this one awaits the owner.
    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/revoke', [...$body, 'step_up' => goodProof('revoke', 'CC-fancy-0001', null, $body)], $user);

    expect($response['status'])->toBe(409)
        ->and($response['body']['error']['code'])->toBe('invalid_state')
        ->and(stepUpEvents())->toBe($before)
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('a step_up that is neither an object nor null is a 422, and no challenge is issued', function (mixed $stepUp): void {
    [$user, $fake] = stepUpApp();

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no', 'step_up' => $stepUp], $user);

    expect($response['status'])->toBe(422)
        ->and($response['body']['error']['code'])->toBe('invalid_request')
        ->and(array_column($fake->calls, 0))->not->toContain('challenge')
        ->and(array_column($fake->calls, 0))->not->toContain('verify');
})->with(['a string' => ['good'], 'a number' => [7], 'true' => [true]]);

test('a null step_up is no proof: the challenge is issued', function (): void {
    [$user] = stepUpApp();

    expect(http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no', 'step_up' => null], $user)['status'])->toBe(428);
});

test('an invalid body is still a 422 before any challenge, and the strict key set is unchanged', function (): void {
    [$user, $fake] = stepUpApp();

    $extra = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no', 'surprise' => 1], $user);
    $missing = http('PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['step_up' => ['token' => 'good']], $user);

    expect($extra['status'])->toBe(422)
        ->and($missing['status'])->toBe(422)
        ->and(array_column($fake->calls, 0))->not->toContain('challenge')
        ->and(array_column($fake->calls, 0))->not->toContain('verify');
});

test('a bad actor hook refuses with invalid_actor before anything is verified', function (): void {
    [$user, $fake] = stepUpApp(new FakeStepUp, fn (): string => "bad\nactor");
    $body = ['reason' => 'no'];

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', [...$body, 'step_up' => goodProof('reject-initiation', 'CC-fancy-0001', null, $body)], $user);

    expect($response['status'])->toBe(500)
        ->and($response['body']['error']['code'])->toBe('invalid_actor')
        ->and($fake->verified())->toBe([]);
});

test('a hook that does not require a step-up for an action lets it through untouched', function (): void {
    [$user, $fake] = stepUpApp(new FakeStepUp(false));

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no'], $user);

    expect($response['status'])->toBe(200)
        ->and(array_column(stepUpEvents(), 'type'))->not->toContain('owner.step_up')
        ->and($fake->calls[0][0])->toBe('required')
        ->and($fake->calls[0][1]->action)->toBe('reject-initiation');
});

test('the audit chain verifies after step-up, refusal and action events', function (): void {
    [$user] = stepUpApp();
    $note = ['note' => 'ok'];

    http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', [...$note, 'step_up' => ['token' => 'bad']], $user);
    http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', [...$note, 'step_up' => goodProof('approve-initiation', 'CC-fancy-0001', null, $note)], $user);
    http('PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 2, 'step_up' => goodProof('blocking-threshold', null, null, ['threshold' => 2])], $user);

    $types = array_column(stepUpEvents(), 'type');

    expect($types)->toContain('owner.step_up_refused')
        ->and(array_count_values($types)['owner.step_up'])->toBe(2)
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});
