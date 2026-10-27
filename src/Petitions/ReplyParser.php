<?php

declare(strict_types=1);

namespace ChangeCouncil\Petitions;

/**
 * COUNCIL-REPLY v1, parsed strictly (design 9.3): the first line is exactly
 * the header, the rest is exactly ONE JSON object, unknown keys are refused,
 * enums are closed and lengths are capped. Returns the structured fields or a
 * problem code; it never guesses at what a malformed reply meant.
 */
final class ReplyParser
{
    public const HEADER = 'COUNCIL-REPLY v1';

    public const KEYS = ['change', 'round', 'nonce', 'digest', 'verdict', 'impact', 'alternative', 'reason'];

    public const IMPACT_KEYS = ['app', 'paths', 'severity', 'note'];

    public const VERDICTS = ['approve', 'conflict', 'abstain'];

    public const SEVERITIES = ['blocker', 'major', 'minor'];

    public const MAX_NOTE = 2000;

    public const MAX_ALTERNATIVE = 8000;

    public const MAX_REASON = 4000;

    public const MAX_IMPACT = 50;

    public const MAX_PATHS = 100;

    /** @return array{ok: true, reply: array}|array{ok: false, problem: string} */
    public static function parse(string $text): array
    {
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
            $reply = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::fail('not_one_json_object');
        }
        if (! is_array($reply) || array_is_list($reply)) {
            return self::fail('not_one_json_object');
        }
        if (array_diff(array_keys($reply), self::KEYS) !== []) {
            return self::fail('unknown_key');
        }
        foreach (['change', 'nonce', 'digest'] as $key) {
            if (! is_string($reply[$key] ?? null) || $reply[$key] === '' || strlen($reply[$key]) > 200) {
                return self::fail('missing_'.$key);
            }
        }
        if (! is_int($reply['round'] ?? null)) {
            return self::fail('bad_round');
        }
        if (! in_array($reply['verdict'] ?? null, self::VERDICTS, true)) {
            return self::fail('bad_verdict');
        }
        $impact = $reply['impact'] ?? [];
        if (! is_array($impact) || ! array_is_list($impact)) {
            return self::fail('bad_impact');
        }
        if (count($impact) > self::MAX_IMPACT) {
            return self::fail('too_many_impact_rows');
        }
        foreach ($impact as $row) {
            if (! is_array($row) || array_is_list($row) || array_diff(array_keys($row), self::IMPACT_KEYS) !== []
                || ! is_string($row['app'] ?? null) || strlen($row['app']) > 200
                || ! is_array($row['paths'] ?? null) || ! array_is_list($row['paths']) || count($row['paths']) > self::MAX_PATHS
                || ! is_string($row['note'] ?? '')) {
                return self::fail('bad_impact');
            }
            foreach ($row['paths'] as $path) {
                if (! is_string($path) || strlen($path) > 500) {
                    return self::fail('bad_impact');
                }
            }
            if (! in_array($row['severity'] ?? null, self::SEVERITIES, true)) {
                return self::fail('bad_severity');
            }
            if (mb_strlen($row['note'] ?? '') > self::MAX_NOTE) {
                return self::fail('note_too_long');
            }
        }
        foreach (['alternative' => self::MAX_ALTERNATIVE, 'reason' => self::MAX_REASON] as $key => $max) {
            if (isset($reply[$key]) && ! is_string($reply[$key])) {
                return self::fail('bad_'.$key);
            }
            if (mb_strlen($reply[$key] ?? '') > $max) {
                return self::fail($key.'_too_long');
            }
        }
        if ($reply['verdict'] === 'conflict' && $impact === []) {
            return self::fail('conflict_needs_impact');
        }
        if ($reply['verdict'] === 'abstain' && trim($reply['reason'] ?? '') === '') {
            return self::fail('abstain_needs_reason');
        }
        $reply['impact'] = $impact;

        return ['ok' => true, 'reply' => $reply];
    }

    private static function fail(string $problem): array
    {
        return ['ok' => false, 'problem' => $problem];
    }
}
