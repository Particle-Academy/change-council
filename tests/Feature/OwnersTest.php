<?php

declare(strict_types=1);

use ChangeCouncil\Facades\Council;
use ChangeCouncil\Http\Authorizer;
use ChangeCouncil\Http\OwnersAuthorizer;
use ChangeCouncil\Laravel\PageRenderer;
use ChangeCouncil\Tests\Support\JsonPageRenderer;
use ChangeCouncil\Tests\Support\User;
use Illuminate\Contracts\Auth\Access\Gate;

/*
 * `council.owners` (env COUNCIL_OWNERS): the package's own owner list, the
 * authorizer a host can opt into with Council::authorizeOwners(). Same
 * semantics as the list fancy-labs built for itself on 2026-10-29, so the lab
 * can switch to this one: emails, comma-separated, compared trimmed and
 * case-insensitively, and an EMPTY list allows nobody.
 */

function ownersApp(array $owners): void
{
    councilApp(['council.owners' => $owners]);
    Council::authorizeOwners();
}

test('config reads COUNCIL_OWNERS as a trimmed, comma-separated list with blanks dropped', function (): void {
    $previous = getenv('COUNCIL_OWNERS');
    putenv('COUNCIL_OWNERS= Owner@Example.com , ,second@example.com,');
    try {
        $config = require dirname(__DIR__, 2).'/config/council.php';
    } finally {
        putenv($previous === false ? 'COUNCIL_OWNERS' : 'COUNCIL_OWNERS='.$previous);
    }

    expect($config['owners'])->toBe(['Owner@Example.com', 'second@example.com']);
});

test('config defaults to an empty owner list', function (): void {
    $previous = getenv('COUNCIL_OWNERS');
    putenv('COUNCIL_OWNERS');
    try {
        $config = require dirname(__DIR__, 2).'/config/council.php';
    } finally {
        if ($previous !== false) {
            putenv('COUNCIL_OWNERS='.$previous);
        }
    }

    expect($config['owners'])->toBe([]);
});

test('an owner is allowed every ability, matched case- and space-insensitively', function (): void {
    ownersApp([' Owner@Example.COM ']);
    $owner = User::create(['name' => 'Ada', 'email' => 'owner@example.com ']);
    $authorizer = app(Authorizer::class);

    expect($authorizer)->toBeInstanceOf(OwnersAuthorizer::class);
    foreach (Authorizer::ABILITIES as $ability) {
        expect($authorizer->allows($owner, $ability, ['ref' => null, 'address' => null]))->toBeTrue($ability);
    }
    expect($authorizer->allows($owner, 'anything-else', []))->toBeFalse();
});

test('anyone else is refused: another login, a user with no email, a guest, a non-user value', function (): void {
    ownersApp(['owner@example.com']);
    $authorizer = app(Authorizer::class);

    foreach ([User::create(['name' => 'Eve', 'email' => 'eve@example.com']), User::create(['name' => 'Nobody']), User::create(['name' => 'Blank', 'email' => '  ']), null, 'owner@example.com', ['email' => 'owner@example.com']] as $principal) {
        foreach (Authorizer::ABILITIES as $ability) {
            expect($authorizer->allows($principal, $ability, []))->toBeFalse();
        }
    }
});

test('an EMPTY owner list allows nobody, including a user whose email is blank', function (): void {
    ownersApp([]);
    $authorizer = app(Authorizer::class);

    expect($authorizer->allows(User::create(['name' => 'Ada', 'email' => 'owner@example.com']), 'view', []))->toBeFalse()
        ->and($authorizer->allows(User::create(['name' => 'Blank', 'email' => '']), 'view', []))->toBeFalse();

    ownersApp(['', '  ']);
    expect(app(Authorizer::class)->allows(User::create(['name' => 'Blank', 'email' => '']), 'owner', []))->toBeFalse();
});

test('the list is read when asked, so a config change takes effect without re-registering', function (): void {
    ownersApp([]);
    $owner = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);
    expect(app(Authorizer::class)->allows($owner, 'view', []))->toBeFalse();

    app('config')->set('council.owners', ['owner@example.com']);
    expect(app(Authorizer::class)->allows($owner, 'view', []))->toBeTrue();
});

test('authorizeOwners defines the council-owner Gate, and leaves a host-defined one alone', function (): void {
    ownersApp(['owner@example.com']);
    $gate = app(Gate::class);

    expect($gate->has('council-owner'))->toBeTrue()
        ->and($gate->forUser(User::create(['name' => 'Ada', 'email' => 'Owner@example.com']))->allows('council-owner'))->toBeTrue()
        ->and($gate->forUser(User::create(['name' => 'Eve', 'email' => 'eve@example.com']))->allows('council-owner'))->toBeFalse();

    councilApp(['council.owners' => ['owner@example.com']]);
    app(Gate::class)->define('council-owner', fn (): bool => false);
    Council::authorizeOwners();
    expect(app(Gate::class)->forUser(User::create(['name' => 'Ada', 'email' => 'owner@example.com']))->allows('council-owner'))->toBeFalse();
});

test('a host that does not opt in keeps the deny-by-default hook, whatever council.owners says', function (): void {
    councilApp(['council.owners' => ['owner@example.com']]);
    app()->instance(PageRenderer::class, new JsonPageRenderer);
    Council::routes(['middleware' => []]);
    $owner = User::create(['name' => 'Ada', 'email' => 'owner@example.com']);

    expect(http('GET', '/api/v1/changes', null, $owner)['status'])->toBe(403);
});

test('over HTTP: the owner reads and acts, another login gets 403 everywhere and the ledger is unchanged', function (): void {
    ownersApp(['owner@example.com']);
    app()->instance(PageRenderer::class, new JsonPageRenderer);
    Council::routes(['middleware' => []]);
    consumer('moic@moic-suite', ['particle-academy/prism']);
    propose();
    $owner = User::create(['name' => 'Ada', 'email' => 'OWNER@example.com']);
    $eve = User::create(['name' => 'Eve', 'email' => 'eve@example.com']);

    expect(http('GET', '/api/v1/changes', null, $eve)['status'])->toBe(403)
        ->and(http('GET', '', null, $eve)['status'])->toBe(403)
        ->and(http('PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify'], $eve)['status'])->toBe(403)
        ->and(\ChangeCouncil\Models\Consumer::query()->value('silence_policy'))->toBe('escalate')
        ->and(http('GET', '/api/v1/changes', null, $owner)['status'])->toBe(200)
        ->and(http('PUT', '/api/v1/consumers/moic@moic-suite/silence-policy', ['policy' => 'proceed_and_notify'], $owner)['status'])->toBe(200);
});
