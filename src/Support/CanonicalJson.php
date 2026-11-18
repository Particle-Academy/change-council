<?php

declare(strict_types=1);

namespace ChangeCouncil\Support;

use ArrayObject;
use InvalidArgumentException;
use stdClass;

/**
 * The one byte-exact JSON form that is hashed and signed. Must agree with
 * `council/lib/record.mjs` byte for byte, so a record signed in PHP verifies in
 * Node (shared fixtures run in both suites: `tests/fixtures/decision-record.json`
 * and `tests/fixtures/canonical-vectors.json`):
 *
 * - object keys sorted by byte order, recursively; list order kept
 * - no whitespace; `/` and non-ASCII unescaped; U+2028/2029 unescaped
 *   (JSON.stringify does not escape them either)
 * - floats refused: PHP and JavaScript do not print them the same way
 *
 * The representation rule (what is a JSON object and what is a list):
 *
 * - a PHP OBJECT (`stdClass`, `ArrayObject`) is ALWAYS a JSON object, whatever
 *   its keys look like: `{"0":"a"}` decoded as a stdClass stays `{"0":"a"}`,
 *   and an empty one is `{}`. This is how a JSON document decoded with
 *   `json_decode($json)` (no assoc) keeps its shape, and it is the only way to
 *   say "object" for keys PHP turns into integers.
 * - a PHP ARRAY is a list exactly when `array_is_list()` (keys 0..n-1 in that
 *   order), so `[]` is `[]` and `['a']` is `["a"]`; any other array is an
 *   object with its keys sorted as strings (`[1 => 'b', 0 => 'a']` is
 *   `{"0":"a","1":"b"}`, never a reordered list).
 *
 * A PHP array with keys 0..n-1 cannot say which it meant, so it is a list. To
 * sign an object whose keys might be integer-like, pass a stdClass. Every
 * signed shape in this package (decision.v1, the audit chain, the owner-action
 * digest) uses string keys for its objects and stdClass for an empty one.
 */
final class CanonicalJson
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Canonical JSON refuses floats; use an integer or a string.');
        }
        if ($value instanceof stdClass) {
            return self::object(get_object_vars($value));
        }
        if ($value instanceof ArrayObject) {
            return self::object($value->getArrayCopy());
        }
        if (! is_array($value)) {
            return json_encode($value, self::FLAGS);
        }
        if (array_is_list($value)) {
            return '['.implode(',', array_map(self::encode(...), $value)).']';
        }

        return self::object($value);
    }

    /** @param array<int|string, mixed> $members */
    private static function object(array $members): string
    {
        ksort($members, SORT_STRING);
        $parts = [];
        foreach ($members as $key => $item) {
            $parts[] = json_encode((string) $key, self::FLAGS).':'.self::encode($item);
        }

        return '{'.implode(',', $parts).'}';
    }
}
