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

/*
 * council.pages.show_nav (0.8.0): a host that renders the pages inside its own
 * navigation turns the row off. The prop defaults to true, so a host that never
 * sends it (or an older controller) still gets the row, and the row's buttons
 * are drawn only inside the showNav guard.
 */
test('every page draws its Changes/Members row only when showNav, which defaults to true', function (): void {
    foreach (['Index.tsx', 'Show.tsx', 'Members.tsx', 'Member.tsx'] as $name) {
        $source = pageSources()[$name];

        expect($source)->toMatch('/showNav\??:\s*boolean/', $name)
            ->and($source)->toMatch('/\bshowNav\s*=\s*true\b/', $name)
            ->and($source)->toMatch('/\{showNav\s*&&\s*\(\s*<>\s*<Button data-action="council-nav-changes"[^>]*>[\s\S]*?<Button data-action="council-nav-members"[^>]*>[\s\S]*?<\/Button>\s*<\/>\s*\)\}/', $name)
            ->and(preg_match_all('/data-action="council-nav-(changes|members)"/', $source))->toBe(2, $name);
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

/*
 * 0.7.0: at a 390px viewport the Change page's five tabs pushed the document
 * to 426px wide (#council-tab-audit ended at right=426.45), so the whole page
 * scrolled sideways. The tab list now scrolls inside its own box: overflow-x
 * on the list, and tabs that neither shrink nor wrap. The list keeps
 * react-fancy's role="tablist" and arrow-key handling, and a focused tab is
 * scrolled into view by the browser.
 */
test('every tab list scrolls sideways inside its own box instead of widening the page', function (): void {
    $found = 0;
    foreach (pageSources() as $name => $source) {
        preg_match_all('/<Tabs\.List\b[^>]*>/s', $source, $lists);
        foreach ($lists[0] as $tag) {
            $found++;
            expect($tag)->toMatch('/className="[^"]*\boverflow-x-auto\b[^"]*"/', "{$name}: {$tag}");
        }
        preg_match_all('/<Tabs\.Tab\b[^>]*>/s', $source, $tabs);
        foreach ($tabs[0] as $tag) {
            expect($tag)->toMatch('/className="[^"]*\bshrink-0\b[^"]*"/', "{$name}: {$tag}")
                ->and($tag)->toMatch('/className="[^"]*\bwhitespace-nowrap\b[^"]*"/', "{$name}: {$tag}");
        }
    }
    expect($found)->toBeGreaterThanOrEqual(1);
});

/*
 * Measured in the same pass, on the Change page at 390px: the silence-policy
 * table had no scroll box, so react-fancy's overflow-hidden frame cut off its
 * last column; and the audit timeline's hashes and JSON, which have no break
 * points, pushed the document to 825px once the Audit tab was open.
 */
test('every table scrolls sideways inside its own frame', function (): void {
    $found = 0;
    foreach (pageSources() as $name => $source) {
        preg_match_all('/<Table(?=[\s>])[^>]*>/s', $source, $m);
        foreach ($m[0] as $tag) {
            $found++;
            expect($tag)->toMatch('/className="[^"]*\boverflow-x-auto\b[^"]*"/', "{$name}: {$tag}");
        }
    }
    expect($found)->toBeGreaterThanOrEqual(8);
});

test('the audit timeline wraps unbroken hashes and JSON instead of widening the page', function (): void {
    preg_match('/<Timeline\b[^>]*?data-testid="council-audit"[^>]*?className="([^"]*)"/s', pageSources()['Show.tsx'], $m);

    expect($m[1] ?? '')->toContain('[overflow-wrap:anywhere]')
        ->and($m[1] ?? '')->toContain('min-w-0');
});

test('every tab carries a stable handle and keeps its id', function (): void {
    $tabs = 0;
    foreach (pageSources() as $name => $source) {
        preg_match_all('/<Tabs\.Tab\b[^>]*>/s', $source, $m);
        foreach ($m[0] as $tag) {
            $tabs++;
            expect($tag)->toMatch('/<Tabs\.Tab\s+data-action="council-tab-[a-z]+"/', "{$name}: {$tag}")
                ->and($tag)->toMatch('/\bid="council-tab-[a-z]+"/', "{$name}: {$tag}");
        }
    }
    expect($tabs)->toBe(5);
});

test('every interactive element carries a stable handle', function (): void {
    foreach (pageSources() as $name => $source) {
        preg_match_all('/<(Button|Select|Input|Textarea|Checkbox)\b[^>]*>/s', $source, $m);
        foreach ($m[0] as $tag) {
            expect($tag)->toMatch('/data-action=|data-field=/', "{$name}: {$tag}");
        }
    }
});

/*
 * A host that requires step-up answers an owner action with 428. The pages
 * hand that to a host-set handler and retry ONCE with the proof; with no
 * handler (or a handler that gives up) the 428 comes back as it is, and every
 * page says in words what happened instead of printing the code.
 */
test('send() retries a step_up_required answer once through the host\'s handler, and never throws', function (): void {
    $api = pageSources()['api.ts'];

    expect($api)->toContain('export type StepUpAction')
        ->and($api)->toContain('export type StepUpHandler')
        ->and($api)->toContain('export function setStepUpHandler(handler: StepUpHandler | null): void')
        ->and($api)->toContain('428')
        ->and($api)->toContain("'step_up_required'")
        ->and($api)->toContain('step_up: proof');
});

test('every page that sends an owner action words the step-up refusals', function (): void {
    $api = pageSources()['api.ts'];

    expect($api)->toContain('export function refusal(')
        ->and($api)->toContain('step_up_failed');
    foreach (['Index.tsx', 'Show.tsx', 'Members.tsx', 'Member.tsx'] as $name) {
        expect(pageSources()[$name])->toMatch('/refusal\(/', $name)
            ->and(pageSources()[$name])->not->toMatch('/Refused: \$\{answer\.json\.error/', $name);
    }
});
