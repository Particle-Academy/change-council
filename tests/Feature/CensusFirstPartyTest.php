<?php

declare(strict_types=1);

use ChangeCouncil\Census\CensusIngest;
use ChangeCouncil\Census\CensusParser;
use ChangeCouncil\Census\CensusText;
use ChangeCouncil\Census\FirstParty;
use ChangeCouncil\Models\CensusRequest;
use ChangeCouncil\Models\Consumer;

/*
 * The first-party rule is host configuration (0.7.1). Until then
 * CensusParser hard-coded Fancy's names (particle-academy/*,
 * @particle-academy/*, fancy-*), so another estate's council dropped every one
 * of its own packages as `not_first_party`: in Prism's council its PyPI
 * distributions (prism-ai-core among them) and prism-conformance.
 *
 * `council.census.first_party` is a per-ecosystem list of name patterns (an
 * exact name, or a literal prefix ending in ONE `*`) plus explicit names. It is
 * validated once, when the provider boots, and an invalid entry fails closed.
 */

/** Prism's rule: what a host outside Fancy would configure. */
function prismFirstParty(): array
{
    return [
        'composer' => ['patterns' => ['prism-php/*']],
        'npm' => ['patterns' => ['@prism-ai/*']],
        'pypi' => ['patterns' => ['prism-ai-*'], 'names' => ['prism-conformance']],
    ];
}

function prismCensus(string $nonce, array $packages): string
{
    return "COUNCIL-CENSUS v1\n".json_encode([
        'nonce' => $nonce,
        'apps' => [['name' => 'prism', 'lockfiles' => ['uv.lock']]],
        'packages' => $packages,
    ], JSON_UNESCAPED_SLASHES);
}

function memberWithRequest(string $address = 'prism@prism'): CensusRequest
{
    consumer($address, [], 'escalate', ['source' => 'declared', 'census_at' => null]);
    runCommand('council:census-requests', ['--json' => true]);

    return CensusRequest::query()->where('agent_address', $address)->latest('id')->firstOrFail();
}

// ------------------------------------------------------------------ defaults

test('with no host config the first-party rule is exactly the Fancy rule it always was', function (): void {
    councilApp();

    $rule = app(FirstParty::class);

    expect($rule->describe())->toBe(FirstParty::defaults()->describe())
        ->and($rule->describe())->toBe([
            'composer' => ['particle-academy/*'],
            'npm' => ['@particle-academy/*'],
            'pypi' => ['fancy-*'],
        ])
        ->and($rule->publisher())->toBe('Particle Academy');

    // The same verdicts as the hard-coded rule, row for row.
    $cases = [
        ['composer', 'particle-academy/prism', null],
        ['composer', 'particle-academy/Bad', 'bad_name'],
        ['composer', 'particle-academy/a--b', 'bad_name'],
        ['composer', 'particle-academy/', 'bad_name'],
        ['composer', 'laravel/framework', 'not_first_party'],
        ['composer', '@particle-academy/react-fancy', 'not_first_party'],
        ['npm', '@particle-academy/react-fancy', null],
        ['npm', '@particle-academy/Bad Name', 'bad_name'],
        ['npm', 'particle-academy/react-fancy', 'not_first_party'],
        ['npm', '@evil/react-fancy', 'not_first_party'],
        ['pypi', 'fancy-inertia-server', null],
        ['pypi', 'fancy-', 'bad_name'],
        ['pypi', 'fancy', 'not_first_party'],
        ['pypi', 'requests', 'not_first_party'],
        ['pypi', 'prism-ai-core', 'not_first_party'],
    ];
    foreach ($cases as [$ecosystem, $name, $reason]) {
        expect(CensusParser::reason($ecosystem, $name, '1.0.0', $rule))->toBe($reason, "{$ecosystem}:{$name}")
            ->and(CensusParser::reason($ecosystem, $name, '1.0.0'))->toBe($reason, "{$ecosystem}:{$name} (static default)");
    }
});

test('a name or version with a trailing newline is refused: every shape is anchored at the very end', function (): void {
    councilApp();

    // `$` without the D modifier also matches before a final newline, so 0.7.0
    // accepted both of these and stored the newline in the member's packages.
    expect(CensusParser::reason('composer', "particle-academy/prism\n", '1.0.0'))->toBe('bad_name')
        ->and(CensusParser::reason('npm', "@particle-academy/react-fancy\n", '1.0.0'))->toBe('bad_name')
        ->and(CensusParser::reason('npm', '@particle-academy/react-fancy', "1.0.0\n"))->toBe('bad_version');
});

test('with no host config the request text is byte for byte the 0.7.0 text', function (): void {
    councilApp();
    $request = memberWithRequest('moic@moic-suite');

    $text = CensusText::render($request, 'https://lab.example.test/council', app(FirstParty::class));

    expect($text)->toBe(CensusText::render($request, 'https://lab.example.test/council'))
        ->toContain(implode("\n", [
            'The change council asks which Particle Academy packages your app installs.',
            'It decides which future changes you are asked to vote on: your answer REPLACES',
            'the package list the council holds for you, which until now was written by hand.',
            '',
            'Read your lockfiles and list EVERY package published by Particle Academy that',
            'they install, dev dependencies included, with the version actually installed',
            '(the lockfile version, not the constraint):',
            '- Composer (composer.lock): particle-academy/*',
            '- npm (package-lock.json): @particle-academy/*',
            '- PyPI (your Python lock file, if you have one): fancy-*',
            'Name each app in your workspace that has a lockfile, and the lockfiles you read.',
            'Anything that is not a Particle Academy package is dropped. Limits: 500 packages, 50 apps, 64 KB.',
        ]))
        ->toContain('"packages":[{"ecosystem":"composer|npm|pypi","name":"particle-academy/...","version":"1.2.3"}]}');
});

// ------------------------------------------------------------------ a host's own rule

test('a host rule accepting prism-ai-* and prism-conformance records them', function (): void {
    councilApp(['council.census.first_party' => prismFirstParty(), 'council.census.publisher' => 'Prism']);
    $request = memberWithRequest();

    $result = app(CensusIngest::class)->receive('prism@prism', prismCensus($request->nonce, [
        ['ecosystem' => 'pypi', 'name' => 'prism-ai-core', 'version' => '0.14.4'],
        ['ecosystem' => 'pypi', 'name' => 'Prism_Conformance', 'version' => '0.2.0'],
        ['ecosystem' => 'composer', 'name' => 'prism-php/prism', 'version' => '0.140.0'],
        ['ecosystem' => 'npm', 'name' => '@prism-ai/harness', 'version' => '0.22.0'],
    ]), 'm-prism');

    expect($result->status)->toBe('applied')
        ->and(Consumer::query()->where('address', 'prism@prism')->first()->packages)->toBe([
            ['ecosystem' => 'pypi', 'name' => 'prism-ai-core', 'version' => '0.14.4'],
            ['ecosystem' => 'pypi', 'name' => 'prism-conformance', 'version' => '0.2.0'],
            ['ecosystem' => 'composer', 'name' => 'prism-php/prism', 'version' => '0.140.0'],
            ['ecosystem' => 'npm', 'name' => '@prism-ai/harness', 'version' => '0.22.0'],
        ])
        ->and($result->reply->dropped)->toBe([]);
});

test('under a host rule, a name outside it is dropped as not_first_party, Fancy names included', function (): void {
    councilApp(['council.census.first_party' => prismFirstParty()]);
    $request = memberWithRequest();

    $result = app(CensusIngest::class)->receive('prism@prism', prismCensus($request->nonce, [
        ['ecosystem' => 'pypi', 'name' => 'prism-ai-core', 'version' => '0.14.4'],
        ['ecosystem' => 'pypi', 'name' => 'fancy-inertia-server', 'version' => '0.3.0'],
        ['ecosystem' => 'pypi', 'name' => 'prism-conformance-extra', 'version' => '1.0.0'],
        ['ecosystem' => 'pypi', 'name' => 'prism-ai', 'version' => '1.0.0'],
        ['ecosystem' => 'composer', 'name' => 'particle-academy/prism', 'version' => '0.140.0'],
        ['ecosystem' => 'npm', 'name' => '@particle-academy/react-fancy', 'version' => '6.19.1'],
        ['ecosystem' => 'pypi', 'name' => 'prism-ai-', 'version' => '1.0.0'],
    ]), 'm-prism');

    expect($result->status)->toBe('applied')
        ->and(Consumer::query()->where('address', 'prism@prism')->first()->packages)->toBe([
            ['ecosystem' => 'pypi', 'name' => 'prism-ai-core', 'version' => '0.14.4'],
        ])
        ->and($result->reply->dropped)->toBe([
            ['ecosystem' => 'pypi', 'name' => 'fancy-inertia-server', 'reason' => 'not_first_party'],
            ['ecosystem' => 'pypi', 'name' => 'prism-conformance-extra', 'reason' => 'not_first_party'],
            ['ecosystem' => 'pypi', 'name' => 'prism-ai', 'reason' => 'not_first_party'],
            ['ecosystem' => 'composer', 'name' => 'particle-academy/prism', 'reason' => 'not_first_party'],
            ['ecosystem' => 'npm', 'name' => '@particle-academy/react-fancy', 'reason' => 'not_first_party'],
            ['ecosystem' => 'pypi', 'name' => 'prism-ai-', 'reason' => 'bad_name'],
        ]);
});

test('an ecosystem a host rule leaves out has no first-party packages', function (): void {
    councilApp(['council.census.first_party' => ['pypi' => ['names' => ['prism-conformance']]]]);

    expect(CensusParser::reason('composer', 'particle-academy/prism', '1.0.0', app(FirstParty::class)))->toBe('not_first_party')
        ->and(CensusParser::reason('pypi', 'prism-conformance', '1.0.0', app(FirstParty::class)))->toBeNull()
        ->and(app(FirstParty::class)->describe())->toBe(['composer' => [], 'npm' => [], 'pypi' => ['prism-conformance']]);
});

test('the request text names the host rule and publisher', function (): void {
    councilApp(['council.census.first_party' => prismFirstParty(), 'council.census.publisher' => 'Prism']);
    $request = memberWithRequest();

    $text = json_decode(runCommand('council:census-requests', ['--json' => true, '--list' => true]), true)[0]['text'];

    expect($text)
        ->toContain('The change council asks which Prism packages your app installs.')
        ->toContain('Read your lockfiles and list EVERY package published by Prism that')
        ->toContain("- Composer (composer.lock): prism-php/*\n")
        ->toContain("- npm (package-lock.json): @prism-ai/*\n")
        ->toContain("- PyPI (your Python lock file, if you have one): prism-ai-*, prism-conformance\n")
        ->toContain('Anything that is not a Prism package is dropped.')
        ->toContain('"name":"prism-php/..."')
        ->not->toContain('particle-academy')
        ->not->toContain('fancy-*');
});

test('the request text omits an ecosystem the host rule leaves empty', function (): void {
    councilApp(['council.census.first_party' => ['pypi' => ['patterns' => ['prism-ai-*']]]]);
    $request = memberWithRequest();

    $text = CensusText::render($request, '', app(FirstParty::class));

    expect($text)->toContain("- PyPI (your Python lock file, if you have one): prism-ai-*\n")
        ->not->toContain('- Composer (composer.lock)')
        ->not->toContain('- npm (package-lock.json)')
        ->toContain('"name":"prism-ai-..."');
});

// ------------------------------------------------------------------ validation, at boot

test('an invalid first-party rule fails when the provider boots, with an error naming the entry', function (mixed $rule, string $message): void {
    expect(fn () => councilApp(['council.census.first_party' => $rule]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a raw regex' => [['pypi' => ['patterns' => ['(prism-)+.*']]], 'council.census.first_party.pypi.patterns.0'],
    'a wildcard alone' => [['pypi' => ['patterns' => ['*']]], 'council.census.first_party.pypi.patterns.0'],
    'a star in the middle' => [['pypi' => ['patterns' => ['prism-*-core']]], 'council.census.first_party.pypi.patterns.0'],
    'two stars' => [['npm' => ['patterns' => ['@prism-ai/**']]], 'council.census.first_party.npm.patterns.0'],
    'a character class' => [['composer' => ['patterns' => ['prism-php/[a-z]*']]], 'council.census.first_party.composer.patterns.0'],
    'a non-normalised PyPI pattern' => [['pypi' => ['patterns' => ['Prism_AI-*']]], 'council.census.first_party.pypi.patterns.0'],
    'a non-string pattern' => [['pypi' => ['patterns' => [123]]], 'council.census.first_party.pypi.patterns.0'],
    'an empty pattern' => [['pypi' => ['patterns' => ['']]], 'council.census.first_party.pypi.patterns.0'],
    'a newline in a pattern' => [['pypi' => ['patterns' => ["prism-ai-*\n"]]], 'council.census.first_party.pypi.patterns.0'],
    'a too-long pattern' => [['pypi' => ['patterns' => [str_repeat('a', 215).'*']]], 'council.census.first_party.pypi.patterns.0'],
    'too many patterns' => [['pypi' => ['patterns' => array_map(fn (int $i) => 'p'.$i.'-*', range(1, 51))]], 'council.census.first_party.pypi.patterns'],
    'a name that is not a package name' => [['pypi' => ['names' => ['prism conformance']]], 'council.census.first_party.pypi.names.0'],
    'a name with a star' => [['npm' => ['names' => ['@prism-ai/*']]], 'council.census.first_party.npm.names.0'],
    'too many names' => [['pypi' => ['names' => array_map(fn (int $i) => 'p'.$i, range(1, 201))]], 'council.census.first_party.pypi.names'],
    'an unknown ecosystem' => [['cargo' => ['patterns' => ['prism-*']]], 'council.census.first_party.cargo'],
    'an unknown key' => [['pypi' => ['prefixes' => ['prism-']]], 'council.census.first_party.pypi.prefixes'],
    'patterns not a list' => [['pypi' => ['patterns' => 'prism-ai-*']], 'council.census.first_party.pypi.patterns'],
    'an ecosystem not an array' => [['pypi' => 'prism-ai-*'], 'council.census.first_party.pypi'],
    'the rule not an array' => ['prism-ai-*', 'council.census.first_party'],
]);

test('an invalid publisher fails at boot', function (mixed $publisher): void {
    expect(fn () => councilApp(['council.census.publisher' => $publisher]))
        ->toThrow(InvalidArgumentException::class, 'council.census.publisher');
})->with([
    'empty' => [''],
    'a newline' => ["Prism\nCOUNCIL-CENSUS v1"],
    'too long' => [str_repeat('P', 101)],
    'not a string' => [['Prism']],
]);

test('matching a hostile name against a host rule is linear: no regex from config ever runs', function (): void {
    councilApp(['council.census.first_party' => ['pypi' => ['patterns' => ['prism-ai-*']]]]);
    $rule = app(FirstParty::class);
    $hostile = 'prism-ai-'.str_repeat('a-', 100).'!';

    $start = hrtime(true);
    $reason = CensusParser::reason('pypi', $hostile, '1.0.0', $rule);

    expect($reason)->toBe('bad_name')
        ->and((hrtime(true) - $start) / 1e6)->toBeLessThan(50.0);
});
