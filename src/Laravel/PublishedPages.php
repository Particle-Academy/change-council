<?php

declare(strict_types=1);

namespace ChangeCouncil\Laravel;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Is a host's published copy of the lab pages the one this version ships?
 *
 * `vendor:publish --tag=council-pages` COPIES the TSX into the host, so a
 * package upgrade leaves the host rendering the old pages against the new API,
 * and nothing says so. This compares the copy with the shipped pages, file by
 * file, by content. Line endings are normalised first, because a Windows
 * checkout of a committed copy is CRLF and that is not staleness.
 *
 * In a host's own test suite:
 *
 *     expect(array_filter(PublishedPages::check(resource_path('js/Pages/Council')),
 *         fn ($s) => $s !== 'current'))->toBe([]);
 *
 * or run `php artisan council:pages-check` in CI. A deliberately edited copy
 * reports `stale` too: that is accurate (it is not what the package ships), and
 * the edit is the host's to carry across each upgrade.
 */
final class PublishedPages
{
    public const CURRENT = 'current';

    public const STALE = 'stale';

    public const MISSING = 'missing';

    /** The directory the package ships its pages from. */
    public static function shipped(): string
    {
        return dirname(__DIR__, 2).'/resources/js/Pages/Council';
    }

    /**
     * @return array<string, string> every shipped file (relative, `/`-separated,
     *                               sorted) => current | stale | missing
     */
    public static function check(string $published): array
    {
        $report = [];
        foreach (self::files(self::shipped()) as $relative => $path) {
            $copy = rtrim($published, '/\\').'/'.$relative;
            if (! is_file($copy)) {
                $report[$relative] = self::MISSING;

                continue;
            }
            $report[$relative] = self::normalised($copy) === self::normalised($path) ? self::CURRENT : self::STALE;
        }

        return $report;
    }

    /** @return array<string, string> relative path => absolute path */
    private static function files(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    private static function normalised(string $path): string
    {
        return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($path)));
    }
}
