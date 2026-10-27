<?php

declare(strict_types=1);

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Laravel\PageRenderer;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Tests\Support\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * The JSON API and the lab pages, through the real router. Every route asks
 * the host's authorization hook; with none registered, everything is refused.
 */

/** @return array{status: int, body: array, raw: string} */
function http(string $method, string $uri, ?array $body = null, ?User $user = null): array
{
    $content = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
    $request = Request::create('/council'.$uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $content);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);
    $response = app('router')->dispatch($request);

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true) ?? [], 'raw' => (string) $response->getContent()];
}

/** A renderer that returns the page name and props as JSON, standing in for Inertia. */
final class JsonPageRenderer implements PageRenderer
{
    public function render(Request $request, string $component, array $props): Response
    {
        return new \Illuminate\Http\JsonResponse(['component' => $component, 'props' => $props]);
    }
}

function httpApp(?Closure $authorize = null): void
{
    councilApp();
    if ($authorize !== null) {
        Council::authorizeUsing($authorize);
    }
    app()->instance(PageRenderer::class, new JsonPageRenderer);
    Council::routes(['middleware' => []]);
    consumer('moic@moic-suite', ['particle-academy/prism']);
}

dataset('every route', [
    'list changes' => ['GET', '/api/v1/changes'],
    'show change' => ['GET', '/api/v1/changes/CC-fancy-0001'],
    'rounds' => ['GET', '/api/v1/changes/CC-fancy-0001/rounds'],
    'consumers' => ['GET', '/api/v1/consumers'],
    'feed' => ['GET', '/api/v1/feed?since=0'],
    'approve initiation' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'ok']],
    'reject initiation' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no']],
    'override' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/override', ['decision' => 'approve', 'reason' => 'r', 'acknowledge' => []]],
    'revoke' => ['POST', '/api/v1/changes/CC-fancy-0001/owner/revoke', ['reason' => 'r']],
    'consumer silence policy' => ['PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify']],
    'change silence policy' => ['PUT', '/api/v1/changes/CC-fancy-0001/silence-policy', ['address' => 'moic@moic-suite', 'policy' => 'proceed_and_notify']],
    'index page' => ['GET', ''],
    'show page' => ['GET', '/changes/CC-fancy-0001'],
]);

test('with no authorization hook, every route is refused', function (string $method, string $path, ?array $body = null): void {
    httpApp();
    propose();

    $response = http($method, $path, $body, User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(403)
        ->and($response['body']['error']['code'])->toBe('forbidden');
})->with('every route');

test('a guest is refused before the hook is asked', function (): void {
    $asked = false;
    httpApp(function () use (&$asked): bool {
        $asked = true;

        return true;
    });
    propose();

    expect(http('GET', '/api/v1/changes')['status'])->toBe(403)
        ->and($asked)->toBeFalse();
});

test('the hook is asked for `view` on reads and `owner` on owner actions, and only a literal true allows', function (): void {
    $asked = [];
    httpApp(function (mixed $user, string $ability, array $subject) use (&$asked) {
        $asked[] = [$ability, $subject['ref'] ?? null];

        return $ability === 'view' ? true : 'yes';
    });
    propose();
    $user = User::create(['name' => 'Ada']);

    expect(http('GET', '/api/v1/changes/CC-fancy-0001', null, $user)['status'])->toBe(200)
        ->and(http('POST', '/api/v1/changes/CC-fancy-0001/owner/revoke', ['reason' => 'r'], $user)['status'])->toBe(403)
        ->and($asked)->toBe([['view', 'CC-fancy-0001'], ['owner', 'CC-fancy-0001']]);
});

test('the list filters by state, package and class', function (): void {
    httpApp(fn (): bool => true);
    propose();
    propose(['title' => 'b', 'source' => 'agent_request', 'initiator' => 'x@y', 'pros' => 'p', 'cons' => 'c', 'classes' => ['schema']]);
    $user = User::create(['name' => 'Ada']);

    expect(array_column(http('GET', '/api/v1/changes', null, $user)['body']['changes'], 'ref'))->toBe(['CC-fancy-0002', 'CC-fancy-0001'])
        ->and(array_column(http('GET', '/api/v1/changes?state=awaiting_owner', null, $user)['body']['changes'], 'ref'))->toBe(['CC-fancy-0002'])
        ->and(array_column(http('GET', '/api/v1/changes?class=schema', null, $user)['body']['changes'], 'ref'))->toBe(['CC-fancy-0002'])
        ->and(array_column(http('GET', '/api/v1/changes?package=particle-academy/nope', null, $user)['body']['changes'], 'ref'))->toBe([]);
});

test('the detail carries rounds, per-agent votes with impact, raw replies as plain strings, and the audit trail', function (): void {
    httpApp(fn (): bool => true);
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict', ['alternative' => '<img src=x onerror=alert(1)> **bold**']);

    $body = http('GET', '/api/v1/changes/CC-fancy-0001', null, User::create(['name' => 'Ada']))['body'];
    $petition = $body['rounds'][0]['petitions'][0];

    expect($body['change']['ref'])->toBe('CC-fancy-0001')
        ->and($petition['agent_address'])->toBe('moic@moic-suite')
        ->and($petition['effective_silence_policy'])->toBe('escalate')
        ->and($petition['votes'][0]['verdict'])->toBe('conflict')
        ->and($petition['votes'][0]['impact'][0]['severity'])->toBe('blocker')
        ->and($petition['votes'][0]['alternative'])->toBe('<img src=x onerror=alert(1)> **bold**')
        ->and($petition['votes'][0]['raw_text'])->toStartWith('COUNCIL-REPLY v1')
        ->and(array_column($body['audit'], 'type'))->toContain('change.proposed', 'vote.recorded')
        ->and($body['owner_actions'])->toContain('override');
});

test('owner actions over HTTP: approve a HITL initiation, set silence policies, override with acknowledgements', function (): void {
    httpApp(fn (): bool => true);
    $user = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);
    propose(['source' => 'github_issue', 'initiator' => 'someone', 'pros' => 'p', 'cons' => 'c']);

    $approved = http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'go'], $user);
    $policy = http('PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify'], $user);
    $perChange = http('PUT', '/api/v1/changes/CC-fancy-0001/silence-policy', ['address' => 'moic@moic-suite', 'policy' => 'escalate'], $user);
    $vote = vote(ledger()->find('CC-fancy-0001'), 'moic@moic-suite', 'conflict')->vote;
    $refused = http('POST', '/api/v1/changes/CC-fancy-0001/owner/override', ['decision' => 'approve', 'reason' => 'ship', 'acknowledge' => []], $user);
    $override = http('POST', '/api/v1/changes/CC-fancy-0001/owner/override', ['decision' => 'approve', 'reason' => 'ship', 'acknowledge' => [$vote->id]], $user);

    expect($approved['body']['change']['state'])->toBe(States::ROUND1_OPEN)
        ->and($policy['body']['consumer']['silence_policy'])->toBe('proceed_and_notify')
        ->and($perChange['body']['change']['silence_overrides'])->toBe(['moic@moic-suite' => 'escalate'])
        ->and($refused['status'])->toBe(422)
        ->and($refused['body']['error'])->toMatchArray(['code' => 'acknowledge_conflicts', 'missing' => [$vote->id]])
        ->and($override['body']['change']['state'])->toBe(States::APPROVED)
        ->and($override['body']['change']['decided_by'])->toBe('owner:owner@example.com');
});

test('bodies are bounded, closed and validated', function (): void {
    httpApp(fn (): bool => true);
    propose();
    $user = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);

    expect(http('POST', '/api/v1/changes/CC-fancy-0001/owner/override', ['decision' => 'approve', 'reason' => 'r', 'acknowledge' => [], 'state' => 'approved'], $user)['status'])->toBe(422)
        ->and(http('POST', '/api/v1/changes/CC-fancy-0001/owner/override', ['decision' => 'maybe', 'reason' => 'r', 'acknowledge' => []], $user)['body']['error']['code'])->toBe('invalid_request')
        ->and(http('PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'yolo'], $user)['body']['error']['code'])->toBe('invalid_policy')
        ->and(http('GET', '/api/v1/changes/CC-fancy-9999', null, $user)['status'])->toBe(404)
        ->and(Consumer::query()->value('silence_policy'))->toBe('escalate');
});

test('the pages render Council/Index and Council/Show with their props and the poll interval', function (): void {
    httpApp(fn (): bool => true);
    propose();
    $user = User::create(['name' => 'Ada']);

    $index = http('GET', '', null, $user)['body'];
    $show = http('GET', '/changes/CC-fancy-0001', null, $user)['body'];

    expect($index['component'])->toBe('Council/Index')
        ->and($index['props']['changes'][0]['ref'])->toBe('CC-fancy-0001')
        ->and($index['props']['pollMs'])->toBe(15000)
        ->and($index['props']['endpoints']['changes'])->toBe('/council/api/v1/changes')
        ->and($show['component'])->toBe('Council/Show')
        ->and($show['props']['detail']['change']['ref'])->toBe('CC-fancy-0001')
        ->and($show['props']['endpoints']['detail'])->toBe('/council/api/v1/changes/CC-fancy-0001');
});

test('without inertia installed, the default page renderer answers 501 instead of a blank page', function (): void {
    councilApp();
    Council::authorizeUsing(fn (): bool => true);
    Council::routes(['middleware' => []]);

    $response = http('GET', '', null, User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(501)
        ->and($response['body']['error']['code'])->toBe('inertia_missing');
});
