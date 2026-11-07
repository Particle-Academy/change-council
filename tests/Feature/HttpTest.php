<?php

declare(strict_types=1);

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Laravel\PageRenderer;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Tests\Support\JsonPageRenderer;
use ChangeCouncil\Tests\Support\User;

/*
 * The JSON API and the lab pages, through the real router. Every route asks
 * the host's authorization hook; with none registered, everything is refused.
 */

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
    'list members' => ['GET', '/api/v1/members'],
    'show member' => ['GET', '/api/v1/members/moic@moic-suite'],
    'member weight' => ['PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 2]],
    'member project' => ['PUT', '/api/v1/members/moic@moic-suite/project', ['project_name' => 'MOIC']],
    'blocking threshold' => ['PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 2]],
    'members page' => ['GET', '/members'],
    'member page' => ['GET', '/members/moic@moic-suite'],
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

test('both pages carry a way back to the host: "/" by default, or council.host_url and council.host_label', function (): void {
    httpApp(fn (): bool => true);
    propose();
    $user = User::create(['name' => 'Ada']);

    expect(http('GET', '', null, $user)['body']['props']['host'])->toBe(['url' => '/', 'label' => 'Back to the app'])
        ->and(http('GET', '/changes/CC-fancy-0001', null, $user)['body']['props']['host'])->toBe(['url' => '/', 'label' => 'Back to the app']);

    app('config')->set('council.host_url', '/labs');
    app('config')->set('council.host_label', 'Back to the lab');
    expect(http('GET', '', null, $user)['body']['props']['host'])->toBe(['url' => '/labs', 'label' => 'Back to the lab'])
        ->and(http('GET', '/changes/CC-fancy-0001', null, $user)['body']['props']['host'])->toBe(['url' => '/labs', 'label' => 'Back to the lab']);

    // An empty URL hides the link rather than drawing one that goes nowhere.
    app('config')->set('council.host_url', '');
    expect(http('GET', '', null, $user)['body']['props']['host'])->toBeNull();
});

test('the list page is told whether the viewer may set a silence policy, by asking the hook for `owner`', function (): void {
    $asked = [];
    httpApp(function (mixed $user, string $ability) use (&$asked): bool {
        $asked[] = $ability;

        return $ability === 'view' || $user->email === 'owner@example.com';
    });
    $owner = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);
    $viewer = User::create(['name' => 'Eve', 'email' => 'eve@example.com']);

    $ownerProps = http('GET', '', null, $owner)['body']['props'];
    $viewerProps = http('GET', '', null, $viewer)['body']['props'];

    expect($ownerProps['canSetPolicy'])->toBeTrue()
        ->and($viewerProps['canSetPolicy'])->toBeFalse()
        ->and($ownerProps['endpoints']['consumers'])->toBe('/council/api/v1/consumers')
        ->and($asked)->toContain('owner')
        // The flag only decides what is drawn: the API still refuses Eve.
        ->and(http('PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify'], $viewer)['status'])->toBe(403)
        ->and(http('PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify'], $owner)['body']['consumer']['silence_policy'])->toBe('proceed_and_notify');
});

test('without inertia installed, the default page renderer answers 501 instead of a blank page', function (): void {
    councilApp();
    Council::authorizeUsing(fn (): bool => true);
    Council::routes(['middleware' => []]);

    $response = http('GET', '', null, User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(501)
        ->and($response['body']['error']['code'])->toBe('inertia_missing');
});

test('GET members: every member with weight, policy, package count, vote stats and census, plus the threshold', function (): void {
    httpApp(fn (): bool => true);
    $change = propose();
    vote($change, 'moic@moic-suite', 'conflict');
    $user = User::create(['name' => 'Ada']);

    $response = http('GET', '/api/v1/members', null, $user);
    $member = $response['body']['members'][0];

    expect($response['status'])->toBe(200)
        ->and(array_keys($member))->toBe([
            'address', 'name', 'workspace', 'project_name', 'project_description', 'project_url', 'weight', 'silence_policy',
            'whitelisted', 'internal', 'source', 'census_at', 'reachable', 'notes', 'packages', 'watches', 'package_count', 'stats',
        ])
        ->and($member['address'])->toBe('moic@moic-suite')
        ->and($member['weight'])->toBe(1)
        ->and($member['stats'])->toMatchArray(['petitions' => 1, 'cast' => 1, 'conflict' => 1, 'pending' => 0])
        ->and($response['body']['blocking_threshold'])->toBe(['value' => 1, 'source' => 'config']);
});

test('GET members/{address}: the profile and the voting record; an unknown member is 404', function (): void {
    httpApp(fn (): bool => true);
    $change = propose();
    vote($change, 'moic@moic-suite', 'abstain');
    $user = User::create(['name' => 'Ada']);

    $body = http('GET', '/api/v1/members/moic@moic-suite', null, $user)['body'];

    expect(array_keys($body))->toBe(['member', 'record', 'blocking_threshold'])
        ->and($body['member']['address'])->toBe('moic@moic-suite')
        ->and(array_keys($body['record'][0]))->toBe(['petition', 'change', 'round', 'tier', 'delivery', 'outcome', 'verdict', 'vote_status', 'late', 'reason', 'impact', 'severity', 'queued_at', 'sent_at', 'replied_at'])
        ->and($body['record'][0]['change'])->toBe(['ref' => 'CC-fancy-0001', 'title' => 'prism 0.140: approvals fail closed', 'state' => 'round1_open'])
        ->and($body['record'][0]['verdict'])->toBe('abstain')
        ->and(http('GET', '/api/v1/members/nobody@nowhere', null, $user)['status'])->toBe(404);
});

test('owner writes over HTTP: weight, project and threshold, each validated, answered with the saved state', function (): void {
    httpApp(fn (): bool => true);
    $owner = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);

    $weight = http('PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 4], $owner);
    $project = http('PUT', '/api/v1/members/moic@moic-suite/project', ['project_name' => 'MOIC', 'project_description' => 'd', 'project_url' => 'https://moic.example.com'], $owner);
    $threshold = http('PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 3], $owner);

    expect($weight['body']['member']['weight'])->toBe(4)
        ->and($project['body']['member']['project_url'])->toBe('https://moic.example.com')
        ->and($threshold['body']['blocking_threshold'])->toBe(['value' => 3, 'source' => 'owner'])
        ->and(http('PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => -1], $owner)['body']['error']['code'])->toBe('invalid_weight')
        ->and(http('PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => '2'], $owner)['body']['error']['code'])->toBe('invalid_weight')
        ->and(http('PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 2, 'extra' => 1], $owner)['status'])->toBe(422)
        ->and(http('PUT', '/api/v1/members/moic@moic-suite/project', ['project_url' => 'javascript:alert(1)'], $owner)['body']['error']['code'])->toBe('invalid_url')
        ->and(http('PUT', '/api/v1/members/nobody@nowhere/weight', ['weight' => 1], $owner)['status'])->toBe(404)
        ->and(http('PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 0], $owner)['body']['error']['code'])->toBe('invalid_threshold')
        ->and(Consumer::query()->value('weight'))->toBe(4);
});

test('the owner writes ask the hook for `owner`, and a viewer is refused', function (): void {
    httpApp(fn (mixed $user, string $ability): bool => $ability === 'view');
    $viewer = User::create(['name' => 'Eve', 'email' => 'eve@example.com']);

    expect(http('GET', '/api/v1/members', null, $viewer)['status'])->toBe(200)
        ->and(http('PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 9], $viewer)['status'])->toBe(403)
        ->and(http('PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 9], $viewer)['status'])->toBe(403)
        ->and(Consumer::query()->value('weight'))->toBe(1);
});

test('the members pages render Council/Members and Council/Member with their props', function (): void {
    httpApp(fn (): bool => true);
    propose();
    $user = User::create(['name' => 'Ada']);

    $list = http('GET', '/members', null, $user)['body'];
    $one = http('GET', '/members/moic@moic-suite', null, $user)['body'];

    expect($list['component'])->toBe('Council/Members')
        ->and($list['props']['members'][0]['address'])->toBe('moic@moic-suite')
        ->and($list['props']['blocking_threshold'])->toBe(['value' => 1, 'source' => 'config'])
        ->and($list['props']['canSetPolicy'])->toBeTrue()
        ->and($list['props']['endpoints'])->toBe(['index' => '/council', 'members' => '/council/members', 'api' => '/council/api/v1/members', 'threshold' => '/council/api/v1/settings/blocking-threshold', 'show' => '/council/changes'])
        ->and($one['component'])->toBe('Council/Member')
        ->and($one['props']['detail']['member']['address'])->toBe('moic@moic-suite')
        ->and($one['props']['detail']['record'])->toHaveCount(1)
        ->and($one['props']['canSetPolicy'])->toBeTrue()
        ->and($one['props']['endpoints'])->toBe(['index' => '/council', 'members' => '/council/members', 'detail' => '/council/api/v1/members/moic%40moic-suite', 'consumers' => '/council/api/v1/consumers', 'show' => '/council/changes'])
        ->and(http('GET', '/members/nobody@nowhere', null, $user)['status'])->toBe(404);
});

test('agent-supplied text goes out as plain JSON strings, escaped for HTML', function (): void {
    httpApp(fn (): bool => true);
    $owner = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);
    http('PUT', '/api/v1/members/moic@moic-suite/project', ['project_name' => '<script>alert(1)</script>', 'project_description' => '<img src=x onerror=alert(1)>'], $owner);

    $raw = http('GET', '/api/v1/members/moic@moic-suite', null, $owner)['raw'];

    expect($raw)->not->toContain('<script>')
        ->and($raw)->not->toContain('<img')
        ->and(json_decode($raw, true)['member']['project_name'])->toBe('<script>alert(1)</script>');
});

test('the change detail shows the threshold arithmetic for each round and the member weight on each petition', function (): void {
    httpApp(fn (): bool => true);
    $change = propose();
    $conflict = vote($change, 'moic@moic-suite', 'conflict')->vote;

    $round = http('GET', '/api/v1/changes/CC-fancy-0001', null, User::create(['name' => 'Ada']))['body']['rounds'][0];

    expect($round['weighing'])->toBe(['blocking_weight' => 1, 'threshold' => 1, 'blocks' => true, 'blocking' => [$conflict->id], 'below_threshold' => [], 'advisory' => [], 'live' => true])
        ->and($round['petitions'][0]['weight'])->toBe(1);
});
