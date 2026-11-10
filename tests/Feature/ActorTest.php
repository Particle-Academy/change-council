<?php

declare(strict_types=1);

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Laravel\PageRenderer;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Decision;
use ChangeCouncil\Tests\Support\JsonPageRenderer;
use ChangeCouncil\Tests\Support\User;

/*
 * CC-fancy-0021: Council::resolveActorUsing(). A host whose owners are not
 * best named by an email (a GitHub login, an SSO subject) maps the
 * authenticated user to the id an owner action records. With no hook the
 * actor is `owner:<email>`, exactly as before. The hook's answer is checked
 * and a bad one is refused: the action does not happen and nothing falls back
 * to the email, because a silent fallback records a name the host did not ask
 * for in an append-only log.
 */

function actorApp(?Closure $resolver = null): User
{
    councilApp();
    Council::authorizeUsing(fn (): bool => true);
    if ($resolver !== null) {
        Council::resolveActorUsing($resolver);
    }
    app()->instance(PageRenderer::class, new JsonPageRenderer);
    Council::routes(['middleware' => []]);
    consumer('moic@moic-suite', ['particle-academy/prism']);
    propose(['source' => 'agent_request', 'initiator' => 'stranger@elsewhere', 'pros' => 'p', 'cons' => 'c']);

    return User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
}

/** @return list<string> */
function ownerActors(): array
{
    return AuditEvent::query()->orderBy('seq')->pluck('actor')->filter(fn (string $a): bool => str_starts_with($a, 'owner:'))->values()->all();
}

test('with no hook the actor is unchanged: owner:<email> in the audit log and the decision', function (): void {
    $user = actorApp();

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no'], $user);

    expect($response['status'])->toBe(200)
        ->and(ownerActors())->toBe(['owner:ada@example.com', 'owner:ada@example.com'])
        ->and(Decision::query()->sole()->decided_by)->toBe('owner:ada@example.com');
});

test('with a hook the id it returns is recorded, for change and member owner actions alike', function (): void {
    $seen = [];
    $user = actorApp(function (mixed $u) use (&$seen): string {
        $seen[] = $u;

        return 'github:wishborn';
    });

    expect(http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no'], $user)['status'])->toBe(200)
        ->and(http('PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 2], $user)['status'])->toBe(200)
        ->and(ownerActors())->toBe(['owner:github:wishborn', 'owner:github:wishborn', 'owner:github:wishborn'])
        ->and(Decision::query()->sole()->decided_by)->toBe('owner:github:wishborn')
        ->and($seen)->each->toBe($user);
});

test('a hook that already answers owner:<id> is not prefixed twice', function (): void {
    $user = actorApp(fn (): string => 'owner:wishborn');

    http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no'], $user);

    expect(Decision::query()->sole()->decided_by)->toBe('owner:wishborn');
});

dataset('invalid actors', [
    'empty' => [''],
    'blank' => ['   '],
    'too long' => [str_repeat('a', 192)],
    'newline' => ["wish\nborn"],
    'carriage return' => ["wish\rborn"],
    'tab' => ["wish\tborn"],
    'NUL' => ["wish\0born"],
    'DEL' => ["wish\x7fborn"],
    'C1 control' => ["wish\u{0085}born"],
    'not a string' => [42],
    'null' => [null],
    'invalid UTF-8' => ["wish\xffborn"],
]);

test('an invalid answer from the hook is refused, nothing is recorded and nothing falls back to the email', function (mixed $answer): void {
    $user = actorApp(fn (): mixed => $answer);
    $before = AuditEvent::query()->count();

    $response = http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no'], $user);

    expect($response['status'])->toBe(500)
        ->and($response['body']['error']['code'])->toBe('invalid_actor')
        ->and($response['body']['error']['detail'])->toContain('resolveActorUsing')
        ->and(AuditEvent::query()->count())->toBe($before)
        ->and(Decision::query()->count())->toBe(0)
        ->and(ledger()->find('CC-fancy-0001')->state)->toBe(States::AWAITING_OWNER);
})->with('invalid actors');

test('191 characters is accepted, the boundary of the limit', function (): void {
    $id = str_repeat('a', 191);
    $user = actorApp(fn (): string => $id);

    expect(http('POST', '/api/v1/changes/CC-fancy-0001/owner/reject-initiation', ['reason' => 'no'], $user)['status'])->toBe(200)
        ->and(Decision::query()->sole()->decided_by)->toBe('owner:'.$id);
});

test('the refusal reaches every owner route, including the settings and member ones', function (): void {
    $user = actorApp(fn (): string => "bad\nactor");

    foreach ([
        ['PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 2]],
        ['PUT', '/api/v1/members/moic@moic-suite/weight', ['weight' => 2]],
        ['PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify']],
        ['PUT', '/api/v1/changes/CC-fancy-0001/silence-policy', ['address' => 'moic@moic-suite', 'policy' => 'proceed_and_notify']],
    ] as [$method, $path, $body]) {
        $response = http($method, $path, $body, $user);
        expect($response['status'])->toBe(500, $path)
            ->and($response['body']['error']['code'])->toBe('invalid_actor', $path);
    }
    expect(ownerActors())->toBe([]);
});

test('the audit chain still verifies with a hook in place', function (): void {
    $user = actorApp(fn (): string => 'github:wishborn');

    http('POST', '/api/v1/changes/CC-fancy-0001/owner/approve-initiation', ['note' => 'ok'], $user);
    http('PUT', '/api/v1/settings/blocking-threshold', ['threshold' => 2], $user);

    expect(ownerActors())->not->toBeEmpty()
        ->and(ledger()->audit()->verify()['ok'])->toBeTrue();
});
