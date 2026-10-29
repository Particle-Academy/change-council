<?php

declare(strict_types=1);

use ChangeCouncil\Laravel\PublishedPages;

/*
 * Hosts publish the pages (vendor:publish --tag=council-pages), so the copy in
 * the host does not move when the package does. `council:pages-check` and
 * PublishedPages::check() compare the host's copy with the pages this version
 * ships, file by file, so a stale copy fails a host's CI instead of quietly
 * rendering an old page against a newer API.
 */

function shippedPagesDir(): string
{
    return dirname(__DIR__, 2).'/resources/js/Pages/Council';
}

/** A fresh copy of the shipped pages in a temp dir, as vendor:publish would leave it. */
function publishedCopy(): string
{
    $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'council-pages-'.bin2hex(random_bytes(6));
    $source = shippedPagesDir();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $destination = $target.DIRECTORY_SEPARATOR.$relative;
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0777, true);
        }
        copy($file->getPathname(), $destination);
    }

    return $target;
}

test('a fresh copy is current, file by file, and the check names every shipped file', function (): void {
    $report = PublishedPages::check(publishedCopy());

    expect($report)->toHaveKeys(['Index.tsx', 'Show.tsx', 'parts/api.ts', 'parts/tone.ts'])
        ->and(array_unique(array_values($report)))->toBe(['current']);
});

test('line endings do not count: a CRLF checkout of the same pages is current', function (): void {
    $copy = publishedCopy();
    $file = $copy.'/Index.tsx';
    $lf = str_replace("\r\n", "\n", (string) file_get_contents($file));
    file_put_contents($file, str_replace("\n", "\r\n", $lf));
    expect(PublishedPages::check($copy)['Index.tsx'])->toBe('current');

    file_put_contents($file, $lf);
    expect(PublishedPages::check($copy)['Index.tsx'])->toBe('current');
});

test('a changed file is stale and a deleted one is missing', function (): void {
    $copy = publishedCopy();
    file_put_contents($copy.'/Show.tsx', '// an older page'.PHP_EOL);
    unlink($copy.'/parts/tone.ts');

    $report = PublishedPages::check($copy);

    expect($report['Show.tsx'])->toBe('stale')
        ->and($report['parts/tone.ts'])->toBe('missing')
        ->and($report['Index.tsx'])->toBe('current');
});

test('council:pages-check exits 0 on a current copy and 1 on a stale one, and says how to fix it', function (): void {
    councilApp();
    $copy = publishedCopy();

    [$ok, $okOutput] = runCommandWithCode('council:pages-check', ['--path' => $copy]);
    file_put_contents($copy.'/Index.tsx', '// old');
    [$stale, $staleOutput] = runCommandWithCode('council:pages-check', ['--path' => $copy]);
    [$absent, $absentOutput] = runCommandWithCode('council:pages-check', ['--path' => $copy.'/nope']);

    expect($ok)->toBe(0)
        ->and($okOutput)->toContain('4 of 4 current')
        ->and($stale)->toBe(1)
        ->and($staleOutput)->toContain('stale   Index.tsx')
        ->and($staleOutput)->toContain('vendor:publish --tag=council-pages --force')
        ->and($absent)->toBe(1)
        ->and($absentOutput)->toContain('missing');
});

test('council:pages-check defaults to the configured publish path under the host\'s resources', function (): void {
    councilApp(['council.pages.publish_path' => 'js/pages/council']);

    [, $output] = runCommandWithCode('council:pages-check');

    expect(str_replace('\\', '/', $output))->toContain('resources/js/pages/council');
});
