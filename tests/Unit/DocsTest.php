<?php

declare(strict_types=1);

/*
 * Hosts copy the authorization sample out of the docs. On a lab with open
 * registration, a sample that lets "any signed-in user" view hands the ledger
 * to anyone who registers. These guard the documented examples, not the code.
 */

function docSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = [$root.'/README.md', ...(glob($root.'/docs/*.md') ?: []), $root.'/src/Council.php'];
    $out = [];
    foreach ($files as $file) {
        $out[basename($file)] = (string) file_get_contents($file);
    }

    return $out;
}

test('no documented authorization sample grants a read to any signed-in user', function (): void {
    foreach (docSources() as $name => $source) {
        expect($source)->not->toMatch("/'view'\s*=>\s*true/", $name)
            ->and($source)->not->toMatch('/\$user\s*!==\s*null/', $name)
            ->and($source)->not->toMatch('/=>\s*true\s*\)\s*;/', $name);
    }
});

test('the quick start leads with the owner-only authorizer and says why', function (): void {
    $quickstart = docSources()['laravel-quickstart.md'];

    expect($quickstart)->toContain('Council::authorizeOwners()')
        ->and($quickstart)->toContain('COUNCIL_OWNERS')
        ->and($quickstart)->toMatch('/open\s+registration/i')
        ->and($quickstart)->toMatch('/empty\s+list\s+allows\s+nobody/i');
});

/*
 * 0.7.0: the quick start suggested `js/pages/council`. The pages render as
 * `Council/Index` etc., so a resolver that maps the name literally finds no
 * `council/Index.tsx` on a case-sensitive filesystem.
 */
test('no doc suggests a lower-case council page folder, and the quick start warns about case', function (): void {
    foreach (docSources() as $name => $source) {
        expect($source)->not->toMatch('#js/[Pp]ages/council\b#', $name);
    }

    expect(docSources()['laravel-quickstart.md'])->toContain('js/pages/Council')
        ->and(docSources()['laravel-quickstart.md'])->toMatch('/case-sensitive filesystems/i');
});

test('the README documents the census first-party rule', function (): void {
    $readme = docSources()['README.md'];

    expect($readme)->toContain('council.census.first_party')
        ->and($readme)->toContain('prism-ai-*')
        ->and($readme)->toContain('never a regex')
        ->and($readme)->toContain('council.census.first_party.pypi.patterns.0')
        ->and(docSources()['census.md'])->toContain('council.census.first_party');
});

test('the README documents resolveActorUsing and its refusal', function (): void {
    $readme = docSources()['README.md'];

    expect($readme)->toContain('Council::resolveActorUsing(')
        ->and($readme)->toContain('191 characters')
        ->and($readme)->toContain('invalid_actor');
});
