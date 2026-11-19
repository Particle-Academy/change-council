<?php

declare(strict_types=1);

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Laravel\PageRenderer;
use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\CensusRequest;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\StepUp\OwnerAction;
use ChangeCouncil\Tests\Support\FakeStepUp;
use ChangeCouncil\Tests\Support\JsonPageRenderer;
use ChangeCouncil\Tests\Support\User;

/*
 * The owner invites an agent onto the council (0.9.0). Until now a member could
 * only arrive as a hand-written row in ConsumerSeed, shipped in a release and
 * applied by `council:seed-consumers` or a migration.
 *
 * An invitation is an owner method behind `owner` and the step-up gate. It adds
 * the member (source `invited`, no packages yet) with an audited
 * `consumer.invited` event, and queues a census request for it: the census
 * request IS the invitation, sent by the clerk over AgentInbox like every other
 * census request. The member's packages, and so which Changes it is required to
 * vote on, come from its census answer. There is no accept/decline protocol: the
 * status shown is the census request's (queued, sent) and the census answer.
 */

const INVITEE = 'newbie@new-workspace';

function inviteApp(Closure $authorize, ?FakeStepUp $stepUp = null): User
{
    councilApp();
    Council::authorizeUsing($authorize);
    if ($stepUp !== null) {
        Council::requireStepUpUsing($stepUp);
    }
    app()->instance(PageRenderer::class, new JsonPageRenderer);
    Council::routes(['middleware' => []]);
    consumer('moic@moic-suite', ['particle-academy/prism']);

    return User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
}

test('inviteMember adds the member with no packages, audits who invited it, and queues its census request', function (): void {
    councilApp();

    $result = ledger()->inviteMember(INVITEE, ['weight' => 2, 'silence_policy' => 'proceed_and_notify', 'project_name' => 'New project'], 'owner@example.com');
    $member = Consumer::query()->where('address', INVITEE)->firstOrFail();
    $invited = AuditEvent::query()->where('type', 'consumer.invited')->firstOrFail();
    $request = CensusRequest::query()->where('agent_address', INVITEE)->firstOrFail();

    expect($result['invited'])->toBeTrue()
        ->and($result['member']->id)->toBe($member->id)
        ->and($member->source)->toBe('invited')
        ->and($member->packages)->toBe([])
        ->and($member->watches)->toBe([])
        ->and($member->weight)->toBe(2)
        ->and($member->silence_policy)->toBe('proceed_and_notify')
        ->and($member->project_name)->toBe('New project')
        ->and($member->workspace)->toBe('new-workspace')
        ->and($member->internal)->toBeFalse()
        ->and($member->whitelisted)->toBeFalse()
        ->and($member->census_at)->toBeNull()
        ->and($invited->actor)->toBe('owner:owner@example.com')
        ->and($invited->change_id)->toBeNull()
        ->and($invited->data)->toBe(['address' => INVITEE, 'weight' => 2, 'silence_policy' => 'proceed_and_notify', 'fields' => ['project_name']])
        ->and($invited->seq)->toBeLessThan(AuditEvent::query()->where('type', 'census.requested')->value('seq'))
        ->and($request->delivery)->toBe('queued')
        ->and($request->consumer_id)->toBe($member->id)
        ->and(AuditEvent::query()->where('type', 'census.requested')->value('data'))->toBe(['request' => $request->id, 'to' => INVITEE])
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('the defaults are weight 1 and escalate, as for every other member', function (): void {
    councilApp();

    $member = ledger()->inviteMember(INVITEE, [], 'owner@example.com')['member'];

    expect($member->weight)->toBe(1)
        ->and($member->silence_policy)->toBe('escalate');
});

test('inviting a current member again is idempotent: nothing is written and no second census request is queued', function (): void {
    councilApp();
    ledger()->inviteMember(INVITEE, ['weight' => 2], 'owner@example.com');
    $events = AuditEvent::query()->count();

    $again = ledger()->inviteMember(INVITEE, ['weight' => 5], 'owner@example.com');

    expect($again['invited'])->toBeFalse()
        ->and($again['member']->weight)->toBe(2)
        ->and(Consumer::query()->where('address', INVITEE)->count())->toBe(1)
        ->and(CensusRequest::query()->where('agent_address', INVITEE)->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBe($events);
});

test('a removed member cannot be invited back: reinstating is not an invitation', function (): void {
    councilApp();
    consumer('gone@elsewhere', ['particle-academy/prism']);
    ledger()->removeMember('gone@elsewhere', 'owner@example.com');

    expect(fn () => ledger()->inviteMember('gone@elsewhere', [], 'owner@example.com'))->toThrow(Refused::class, 'invalid_state');
});

test('the address must be name@workspace, and every field is validated before anything is written', function (string $address, array $fields, string $reason): void {
    councilApp();

    expect(fn () => ledger()->inviteMember($address, $fields, 'owner@example.com'))->toThrow(Refused::class, $reason)
        ->and(Consumer::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
})->with([
    'no workspace' => ['newbie', [], 'invalid_address'],
    'empty name' => ['@ws', [], 'invalid_address'],
    'two ats' => ['a@b@c', [], 'invalid_address'],
    'a space' => ['new bie@ws', [], 'invalid_address'],
    'a slash' => ['a/b@ws', [], 'invalid_address'],
    'too long' => [str_repeat('a', 120).'@ws', [], 'invalid_address'],
    'negative weight' => [INVITEE, ['weight' => -1], 'invalid_weight'],
    'string weight' => [INVITEE, ['weight' => '2'], 'invalid_weight'],
    'unknown policy' => [INVITEE, ['silence_policy' => 'ignore'], 'invalid_policy'],
    'unsafe url' => [INVITEE, ['project_url' => 'javascript:alert(1)'], 'invalid_url'],
    'unknown field' => [INVITEE, ['whitelisted' => true], 'invalid_request'],
]);

test('the API invites only behind `owner`: a viewer and a guest are refused and nothing is written', function (): void {
    inviteApp(fn (mixed $user, string $ability): bool => $ability === 'view');
    $viewer = User::create(['name' => 'Eve', 'email' => 'eve@example.com']);

    expect(http('POST', '/api/v1/members', ['address' => INVITEE], $viewer)['status'])->toBe(403)
        ->and(http('POST', '/api/v1/members', ['address' => INVITEE])['status'])->toBe(403)
        ->and(Consumer::query()->where('address', INVITEE)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('type', 'consumer.invited')->count())->toBe(0);
});

test('the owner invites over HTTP: 201 with the new member, its census request queued', function (): void {
    $owner = inviteApp(fn (): bool => true);

    $response = http('POST', '/api/v1/members', ['address' => INVITEE, 'weight' => 3], $owner);

    expect($response['status'])->toBe(201)
        ->and($response['body']['invited'])->toBeTrue()
        ->and($response['body']['member']['address'])->toBe(INVITEE)
        ->and($response['body']['member']['source'])->toBe('invited')
        ->and($response['body']['member']['weight'])->toBe(3)
        ->and($response['body']['member']['census_request']['delivery'])->toBe('queued')
        ->and($response['body']['consumer']['address'])->toBe(INVITEE)
        ->and(AuditEvent::query()->where('type', 'consumer.invited')->value('actor'))->toBe('owner:ada@example.com');

    // The clerk's census outbox now holds the invitation, addressed to the agent.
    expect(array_column(json_decode(runCommand('council:census-requests', ['--list' => true, '--json' => true]), true), 'to'))->toBe([INVITEE]);
});

test('a duplicate invite over HTTP is a 200 with invited false, writes nothing and asks for no step-up', function (): void {
    $fake = new FakeStepUp;
    $owner = inviteApp(fn (): bool => true, $fake);
    $events = AuditEvent::query()->count();

    $response = http('POST', '/api/v1/members', ['address' => 'moic@moic-suite', 'weight' => 4], $owner);

    expect($response['status'])->toBe(200)
        ->and($response['body']['invited'])->toBeFalse()
        ->and($response['body']['member']['address'])->toBe('moic@moic-suite')
        ->and($response['body']['member']['weight'])->toBe(1)
        ->and(AuditEvent::query()->count())->toBe($events)
        ->and(CensusRequest::query()->count())->toBe(0)
        ->and($fake->calls)->toBe([]);
});

test('without a step-up proof the invite is a 428 bound to the address and params, and nothing is written', function (): void {
    $owner = inviteApp(fn (): bool => true, new FakeStepUp);
    $body = ['address' => INVITEE, 'weight' => 2];

    $response = http('POST', '/api/v1/members', $body, $owner);
    $expected = new OwnerAction(OwnerAction::MEMBER_INVITE, null, INVITEE, $body);

    expect($response['status'])->toBe(428)
        ->and($response['body']['error']['code'])->toBe('step_up_required')
        ->and($response['body']['error']['action']['digest'])->toBe($expected->digest())
        ->and(Consumer::query()->where('address', INVITEE)->exists())->toBeFalse()
        ->and(CensusRequest::query()->count())->toBe(0)
        ->and(AuditEvent::query()->whereIn('type', ['consumer.invited', 'owner.step_up', 'census.requested'])->count())->toBe(0);
});

test('a refused proof is a 403 and nothing but the refusal is written', function (): void {
    $owner = inviteApp(fn (): bool => true, new FakeStepUp);

    $response = http('POST', '/api/v1/members', ['address' => INVITEE, 'step_up' => ['token' => 'bad']], $owner);

    expect($response['status'])->toBe(403)
        ->and($response['body']['error']['code'])->toBe('step_up_failed')
        ->and(Consumer::query()->where('address', INVITEE)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('type', 'owner.step_up_refused')->count())->toBe(1)
        ->and(AuditEvent::query()->where('type', 'consumer.invited')->count())->toBe(0);
});

test('a good proof invites, with owner.step_up then consumer.invited then census.requested in one chain', function (): void {
    $owner = inviteApp(fn (): bool => true, new FakeStepUp);
    $body = ['address' => INVITEE];

    $response = http('POST', '/api/v1/members', [...$body, 'step_up' => goodProof(OwnerAction::MEMBER_INVITE, null, INVITEE, $body)], $owner);
    $types = AuditEvent::query()->orderBy('seq')->pluck('type')->all();

    expect($response['status'])->toBe(201)
        ->and(array_slice($types, -3))->toBe(['owner.step_up', 'consumer.invited', 'census.requested'])
        ->and(AuditEvent::query()->where('type', 'owner.step_up')->value('data')['action'])->toBe('member-invite')
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});

test('an invalid body is a 422 before any challenge', function (array $body): void {
    $fake = new FakeStepUp;
    $owner = inviteApp(fn (): bool => true, $fake);

    expect(http('POST', '/api/v1/members', $body, $owner)['status'])->toBe(422)
        ->and($fake->calls)->toBe([]);
})->with([
    'no address' => [['weight' => 1]],
    'address not a string' => [['address' => 5]],
    'unknown key' => [['address' => INVITEE, 'packages' => []]],
    'bad address' => [['address' => 'not-an-address']],
]);

test('re-inviting a removed member over HTTP is a 409', function (): void {
    $owner = inviteApp(fn (): bool => true);
    ledger()->removeMember('moic@moic-suite', 'owner@example.com');

    expect(http('POST', '/api/v1/members', ['address' => 'moic@moic-suite'], $owner)['status'])->toBe(409);
});

test('the Index page gets the invite endpoint', function (): void {
    $owner = inviteApp(fn (): bool => true);

    $page = http('GET', '/', null, $owner);

    expect($page['body']['props']['endpoints']['invite'] ?? null)->toBe('/council/api/v1/members');
});
