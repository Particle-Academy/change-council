<?php

declare(strict_types=1);

namespace ChangeCouncil\Census;

/**
 * COUNCIL-CENSUS v1, parsed strictly, in the same shape as ReplyParser: the
 * first line is exactly the header, the rest is exactly ONE JSON object,
 * unknown keys are refused, the ecosystem enum is closed and every size is
 * capped. A malformed reply is refused whole with a problem code.
 *
 * A well-formed row whose name is not first-party (or whose name or version is
 * not a plain identifier, or that repeats an earlier row) is DROPPED with a
 * recorded reason rather than refusing the census: one stray lockfile entry
 * should not cost a member its whole census. Only the accepted rows can ever
 * reach a member's `packages`.
 */
final class CensusParser
{
    public const HEADER = 'COUNCIL-CENSUS v1';

    public const KEYS = ['nonce', 'apps', 'packages'];

    public const APP_KEYS = ['name', 'lockfiles'];

    public const PACKAGE_KEYS = ['ecosystem', 'name', 'version'];

    public const ECOSYSTEMS = ['composer', 'npm', 'pypi'];

    /** The whole message, in bytes. */
    public const MAX_BYTES = 65536;

    public const MAX_PACKAGES = 500;

    public const MAX_APPS = 50;

    public const MAX_LOCKFILES = 10;

    public const MAX_NONCE = 200;

    public const MAX_APP_NAME = 200;

    public const MAX_LOCKFILE = 500;

    /** npm's own limit; composer and PyPI names are shorter in practice. */
    public const MAX_NAME = 214;

    public const MAX_VERSION = 100;

    /** @var array<string, string> ecosystem => the prefix every first-party name carries */
    public const PREFIXES = [
        'composer' => 'particle-academy/',
        'npm' => '@particle-academy/',
        'pypi' => 'fancy-',
    ];

    /** @var array<string, string> ecosystem => the full name a first-party package must match */
    private const NAMES = [
        'composer' => '#^particle-academy/[a-z0-9](?:[_.-]?[a-z0-9]+)*$#',
        'npm' => '#^@particle-academy/[a-z0-9][a-z0-9._-]*$#',
        'pypi' => '#^fancy(?:-[a-z0-9]+)+$#',
    ];

    /** An installed version: a plain identifier, never a range, a URL or a command. */
    private const VERSION = '#^v?[0-9A-Za-z][0-9A-Za-z.+_-]*$#';

    /**
     * @return array{ok: true, census: array{nonce: string, apps: list<array{name: string, lockfiles: list<string>}>, packages: list<array{ecosystem: string, name: string, version: string}>, dropped: list<array{ecosystem: string, name: string, reason: string}>}}|array{ok: false, problem: string}
     */
    public static function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            return self::fail('too_large');
        }
        $text = str_replace("\r\n", "\n", $text);
        $newline = strpos($text, "\n");
        $first = $newline === false ? $text : substr($text, 0, $newline);
        if (rtrim($first) !== self::HEADER) {
            return self::fail('bad_header');
        }
        $body = trim($newline === false ? '' : substr($text, $newline + 1));
        if ($body === '' || $body[0] !== '{' || ! str_ends_with($body, '}')) {
            return self::fail('not_one_json_object');
        }
        try {
            $census = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::fail('not_one_json_object');
        }
        if (! is_array($census) || array_is_list($census)) {
            return self::fail('not_one_json_object');
        }
        if (array_diff(array_keys($census), self::KEYS) !== []) {
            return self::fail('unknown_key');
        }
        if (! is_string($census['nonce'] ?? null) || $census['nonce'] === '' || strlen($census['nonce']) > self::MAX_NONCE) {
            return self::fail('missing_nonce');
        }

        $apps = $census['apps'] ?? null;
        if (! is_array($apps) || ! array_is_list($apps)) {
            return self::fail('bad_apps');
        }
        if (count($apps) > self::MAX_APPS) {
            return self::fail('too_many_apps');
        }
        foreach ($apps as $app) {
            if (! is_array($app) || ($app !== [] && array_is_list($app)) || array_diff(array_keys($app), self::APP_KEYS) !== []
                || ! is_string($app['name'] ?? null) || $app['name'] === '' || mb_strlen($app['name']) > self::MAX_APP_NAME
                || ! is_array($app['lockfiles'] ?? null) || ! array_is_list($app['lockfiles']) || count($app['lockfiles']) > self::MAX_LOCKFILES) {
                return self::fail('bad_apps');
            }
            foreach ($app['lockfiles'] as $lockfile) {
                if (! is_string($lockfile) || mb_strlen($lockfile) > self::MAX_LOCKFILE) {
                    return self::fail('bad_apps');
                }
            }
        }

        $rows = $census['packages'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return self::fail('bad_packages');
        }
        if (count($rows) > self::MAX_PACKAGES) {
            return self::fail('too_many_packages');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ($row !== [] && array_is_list($row)) || array_diff(array_keys($row), self::PACKAGE_KEYS) !== []
                || ! is_string($row['name'] ?? null) || $row['name'] === '' || strlen($row['name']) > self::MAX_NAME
                || ! is_string($row['version'] ?? null) || $row['version'] === '' || strlen($row['version']) > self::MAX_VERSION
                || ! is_string($row['ecosystem'] ?? null)) {
                return self::fail('bad_package');
            }
            if (! in_array($row['ecosystem'], self::ECOSYSTEMS, true)) {
                return self::fail('bad_ecosystem');
            }
        }

        $packages = [];
        $dropped = [];
        $seen = [];
        foreach ($rows as $row) {
            $ecosystem = $row['ecosystem'];
            $name = $ecosystem === 'pypi' ? self::pypiName($row['name']) : $row['name'];
            $reason = self::reason($ecosystem, $name, $row['version']);
            if ($reason === null && isset($seen[$ecosystem.':'.$name])) {
                $reason = 'duplicate';
            }
            if ($reason !== null) {
                $dropped[] = ['ecosystem' => $ecosystem, 'name' => $row['name'], 'reason' => $reason];

                continue;
            }
            $seen[$ecosystem.':'.$name] = true;
            $packages[] = ['ecosystem' => $ecosystem, 'name' => $name, 'version' => $row['version']];
        }

        return ['ok' => true, 'census' => [
            'nonce' => $census['nonce'],
            'apps' => array_map(fn (array $a) => ['name' => $a['name'], 'lockfiles' => $a['lockfiles']], $apps),
            'packages' => $packages,
            'dropped' => $dropped,
        ]];
    }

    /** null when the row is a first-party package at a plain version, else why it is dropped. */
    public static function reason(string $ecosystem, string $name, string $version): ?string
    {
        $prefix = self::PREFIXES[$ecosystem] ?? null;
        if ($prefix === null || ! str_starts_with($name, $prefix)) {
            return 'not_first_party';
        }
        if (preg_match(self::NAMES[$ecosystem], $name) !== 1) {
            return 'bad_name';
        }
        if (preg_match(self::VERSION, $version) !== 1) {
            return 'bad_version';
        }

        return null;
    }

    /**
     * PEP 503 normalisation (lower case, runs of `-`, `_`, `.` become one `-`)
     * when the name is a valid PEP 508 name; otherwise unchanged, so it fails.
     */
    private static function pypiName(string $name): string
    {
        if (preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$#', $name) !== 1) {
            return $name;
        }

        return strtolower((string) preg_replace('#[-_.]+#', '-', $name));
    }

    private static function fail(string $problem): array
    {
        return ['ok' => false, 'problem' => $problem];
    }
}
