<?php

declare(strict_types=1);

/*
 * The lab pages ship as TSX for the host's Vite to compile. PHP cannot render
 * them, so these are source guards; the browser check lives in the docs
 * (docs/lab-ui.md) and was run against a real host.
 *
 * - Fancy Exclusive: UI imports come from Fancy packages, React and Inertia only.
 * - Replies are DATA: no innerHTML and no markdown renderer anywhere.
 * - One php-cgi worker: polling is slow and paused while the tab is hidden.
 */

function pageSources(): array
{
    $files = glob(dirname(__DIR__, 2).'/resources/js/Pages/Council/*.tsx') ?: [];
    $files = [...$files, ...(glob(dirname(__DIR__, 2).'/resources/js/Pages/Council/parts/*.ts*') ?: [])];
    $out = [];
    foreach ($files as $file) {
        $out[basename($file)] = file_get_contents($file);
    }

    return $out;
}

test('every page exists', function (): void {
    expect(array_keys(pageSources()))->toContain('Index.tsx', 'Show.tsx', 'Members.tsx', 'Member.tsx', 'nav.ts');
});

test('every page draws the Council nav, with Members in it', function (): void {
    foreach (['Index.tsx', 'Show.tsx', 'Members.tsx', 'Member.tsx'] as $name) {
        expect(pageSources()[$name])->toMatch('/data-action="council-nav-changes"/', $name)
            ->and(pageSources()[$name])->toMatch('/data-action="council-nav-members"/', $name);
    }
});

test('the members list is a sortable Fancy table', function (): void {
    $members = pageSources()['Members.tsx'];

    expect($members)->toContain('<Table.Column')
        ->and(preg_match_all('/<Table\.Column\b[^>]*sortKey=/', $members))->toBeGreaterThanOrEqual(8)
        ->and($members)->toContain('data-testid="council-members"');
});

test('the profile links a project URL only when it is http or https, and the owner controls are drawn only for an owner', function (): void {
    $member = pageSources()['Member.tsx'];

    expect($member)->toMatch('/safeUrl\(/')
        ->and(pageSources()['nav.ts'])->toContain('/^https?:\/\//i')
        ->and($member)->toContain('data-field="member-weight"')
        ->and($member)->toContain('data-field="member-silence-policy"')
        ->and($member)->toContain('data-field="project-url"')
        ->and($member)->toMatch('/canSetPolicy\s*&&/');
});

test('the profile has an owner-only "Remove from council" control, staged behind a confirmation', function (): void {
    $member = pageSources()['Member.tsx'];

    expect($member)->toContain('data-action="remove-member"')
        ->and($member)->toContain('data-action="confirm-remove-member"')
        ->and($member)->toContain('data-action="cancel-remove-member"')
        ->and($member)->toContain('Remove from council')
        ->and($member)->toContain('/remove`');
});

test('the members list shows removed members apart, under Former members', function (): void {
    $members = pageSources()['Members.tsx'];

    expect($members)->toContain('Former members')
        ->and($members)->toContain('data-testid="council-former-members"')
        ->and($members)->toContain('former_members');
});

test('the change page shows the threshold arithmetic', function (): void {
    expect(pageSources()['Show.tsx'])->toContain('Blocking weight')
        ->and(pageSources()['Show.tsx'])->toContain('of threshold');
});

test('imports come only from Fancy packages, React, Inertia and the pages\' own parts', function (): void {
    foreach (pageSources() as $name => $source) {
        preg_match_all("/from\\s+'([^']+)'/", $source, $m);
        foreach ($m[1] as $module) {
            expect(preg_match('#^(@particle-academy/(react-fancy|fancy-[a-z-]+)|react|@inertiajs/react|\./parts/[A-Za-z]+|\./[A-Za-z]+)$#', $module))
                ->toBe(1, "{$name} imports {$module}");
        }
    }
});

test('no page renders reply text as HTML or markdown', function (): void {
    foreach (pageSources() as $name => $source) {
        expect($source)->not->toContain('dangerouslySetInnerHTML', "{$name}")
            ->and($source)->not->toContain('ContentRenderer', "{$name}")
            ->and($source)->not->toMatch('/markdown/i', "{$name}");
    }
});

test('polling is slow and visibility-gated', function (): void {
    $sources = implode("\n", pageSources());

    expect($sources)->toContain("document.visibilityState === 'visible'")
        ->and($sources)->toContain('visibilitychange')
        ->and($sources)->toMatch('/Math\.max\(\s*15_?000/');
});

test('both pages draw the way back to the host, and hide it when there is none', function (): void {
    foreach (['Index.tsx', 'Show.tsx'] as $name) {
        $source = pageSources()[$name];
        // toContain is variadic (every argument is a needle), so the file name
        // goes in toMatch's message slot only.
        expect($source)->toMatch('/data-action="council-host"/', $name)
            ->and($source)->toMatch('/host\s*&&/', $name)
            ->and($source)->toMatch('/href=\{host\.url\}/', $name);
    }
});

test('the list page sets a consumer\'s silence policy itself, only for an owner, and no longer points at the raw API', function (): void {
    $index = pageSources()['Index.tsx'];

    expect($index)->not->toContain('via the API')
        ->and($index)->toContain('data-field="consumer-silence-policy"')
        ->and($index)->toMatch('/canSetPolicy\s*\?/')
        ->and($index)->toContain('/silence-policy`');
});

/*
 * The owner actions live on the Change's own page, so the list page is the only
 * way there. On 0.2.0 that way was the ref drawn as plain text (underlined on
 * hover only), and the "awaiting your approval" callout linked nowhere: an
 * owner sat on the list page for hours with a Change waiting and no visible
 * thing to press, and no owner event was ever recorded.
 */
test('the list page gives every Change waiting for the owner a visible button to its page', function (): void {
    $index = pageSources()['Index.tsx'];

    // One button per Change that waits for the owner (awaiting_owner or
    // escalated), inside the callout that says so, pointing at its page.
    expect($index)->toMatch('/data-testid="council-needs-owner"[\s\S]*?data-action="council-review"/')
        ->and($index)->toMatch('/<Button\b[^>]*data-action="council-review"[^>]*href=\{`\$\{endpoints\.show\}\/\$\{c\.ref\}`\}/s')
        ->and($index)->toMatch("/c\\.state === 'awaiting_owner' \\|\\| c\\.state === 'escalated'/");
});

test('the list page draws each ref as a link, not as text that underlines on hover', function (): void {
    $index = pageSources()['Index.tsx'];

    preg_match('/<Link\b[^>]*id=\{`council-open-[^>]*>/s', $index, $m);
    expect($m)->not->toBeEmpty();
    preg_match('/className="([^"]*)"/', $m[0], $cls);
    $classes = explode(' ', $cls[1] ?? '');
    expect($classes)->toContain('underline')
        ->and(array_filter($classes, static fn (string $c): bool => str_starts_with($c, 'text-') && ! str_starts_with($c, 'text-sm')))->not->toBeEmpty();
});

test('every interactive element carries a stable handle', function (): void {
    foreach (pageSources() as $name => $source) {
        preg_match_all('/<(Button|Select|Input|Textarea|Checkbox)\b[^>]*>/s', $source, $m);
        foreach ($m[0] as $tag) {
            expect($tag)->toMatch('/data-action=|data-field=/', "{$name}: {$tag}");
        }
    }
});
