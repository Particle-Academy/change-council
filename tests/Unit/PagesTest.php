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

test('both pages exist', function (): void {
    expect(array_keys(pageSources()))->toContain('Index.tsx', 'Show.tsx');
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
