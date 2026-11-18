<?php

declare(strict_types=1);

use ChangeCouncil\Support\CanonicalJson;

/*
 * The representation rule: a PHP object (stdClass, ArrayObject) is ALWAYS a
 * JSON object, whatever its keys look like; a PHP array is a list exactly when
 * array_is_list(), otherwise an object. Before 0.8.1 a stdClass with keys
 * 0..n-1 collapsed into a list, so `{"0":"a"}` and `["a"]` signed the same.
 */

function canonicalVectors(): array
{
    $file = json_decode((string) file_get_contents(__DIR__.'/../fixtures/canonical-vectors.json'), true, 16, JSON_THROW_ON_ERROR);
    $out = [];
    foreach ($file['vectors'] as $v) {
        $out[$v['name']] = [$v['input'], $v['canonical']];
    }

    return $out;
}

test('JS-parity vector', function (string $input, string $canonical): void {
    // Decoded preserving objects: a JSON object is a stdClass, a JSON array a PHP array.
    expect(CanonicalJson::encode(json_decode($input, false, 32, JSON_THROW_ON_ERROR)))->toBe($canonical);
})->with(canonicalVectors());

test('a stdClass with key 0 stays an object', function (): void {
    $o = new stdClass;
    $o->{'0'} = 'a';
    expect(CanonicalJson::encode($o))->toBe('{"0":"a"}')
        ->and(CanonicalJson::encode((object) ['a', 'b']))->toBe('{"0":"a","1":"b"}')
        ->and(CanonicalJson::encode(['x' => (object) ['a']]))->toBe('{"x":{"0":"a"}}');
});

test('an ArrayObject is an object, with its contents', function (): void {
    expect(CanonicalJson::encode(new ArrayObject(['a'])))->toBe('{"0":"a"}')
        ->and(CanonicalJson::encode(new ArrayObject(['b' => 1, 'a' => 2])))->toBe('{"a":2,"b":1}')
        ->and(CanonicalJson::encode(new ArrayObject))->toBe('{}');
});

test('empty stdClass is {} and empty array is []', function (): void {
    expect(CanonicalJson::encode(new stdClass))->toBe('{}')
        ->and(CanonicalJson::encode([]))->toBe('[]')
        ->and(CanonicalJson::encode(['a' => [], 'b' => new stdClass]))->toBe('{"a":[],"b":{}}');
});

test('a PHP array is a list exactly when array_is_list', function (): void {
    expect(CanonicalJson::encode(['a', 'b']))->toBe('["a","b"]')
        ->and(CanonicalJson::encode([1 => 'b', 0 => 'a']))->toBe('{"0":"a","1":"b"}')
        ->and(CanonicalJson::encode([1 => 'a']))->toBe('{"1":"a"}')
        ->and(CanonicalJson::encode(['b' => 1, 0 => 'x', 10 => 2, 9 => 3]))->toBe('{"0":"x","10":2,"9":3,"b":1}');
});

test('a mixed-key stdClass sorts its keys as strings', function (): void {
    $o = json_decode('{"b":1,"0":"x","10":2,"9":3}');
    expect(CanonicalJson::encode($o))->toBe('{"0":"x","10":2,"9":3,"b":1}');
});

test('floats are refused inside objects too', function (): void {
    expect(fn () => CanonicalJson::encode((object) ['n' => 1.5]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CanonicalJson::encode(new ArrayObject([1.5])))->toThrow(InvalidArgumentException::class);
});
