<?php

declare(strict_types=1);

/*
 * The PHP public surface of a set of files, by PHP's own built-in tokenizer.
 * No autoloading, no reflection, no php-parser (third-party): the files are
 * never executed, so an old tag's tree needs no `composer install` to compare.
 *
 * stdin:  {"files": {"src/Client.php": "<?php ...", ...}}
 * stdout: {"classes": {"Widget\\Client": {kind, final, abstract, internal, file, line,
 *          methods: {lower: {name, static, abstract, final, line, params: [...], returns}},
 *          constants: [...], properties: [...], cases: [...]}}}
 *
 * Public members only. Protected members matter to subclasses and are NOT
 * compared; that is a stated limit, not an oversight.
 */

$input = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($input) || ! is_array($input['files'] ?? null)) {
    fwrite(STDERR, "surface.php: expected {\"files\": {...}} on stdin\n");
    exit(2);
}

$classes = [];
foreach ($input['files'] as $path => $code) {
    foreach (surface((string) $path, (string) $code) as $fqcn => $class) {
        $classes[$fqcn] = $class;
    }
}

echo json_encode(['classes' => (object) $classes], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

/** @return array<string, array<string, mixed>> */
function surface(string $path, string $code): array
{
    try {
        $tokens = token_get_all($code, TOKEN_PARSE);
    } catch (\Throwable) {
        $tokens = token_get_all($code);
    }
    $tokens = array_values(array_filter($tokens, fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT], true)));
    $count = count($tokens);
    $text = fn ($t) => is_array($t) ? $t[1] : $t;
    $id = fn ($t) => is_array($t) ? $t[0] : $t;
    $line = fn (int $i) => is_array($tokens[$i]) ? $tokens[$i][2] : (is_array($tokens[$i - 1] ?? null) ? $tokens[$i - 1][2] : 0);

    $namespace = '';
    $out = [];
    $depth = 0;
    $doc = null;
    $class = null; // ['fqcn' => , 'depth' => ]
    $modifiers = [];

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        $kind = $id($t);

        if ($kind === T_DOC_COMMENT) {
            $doc = $t[1];
            continue;
        }
        if ($kind === T_NAMESPACE && $class === null) {
            $name = '';
            for ($j = $i + 1; $j < $count && ! in_array($text($tokens[$j]), [';', '{'], true); $j++) {
                $name .= $text($tokens[$j]);
            }
            $namespace = trim($name);
            $i = $j - 1;
            continue;
        }
        if ($kind === '{' || $kind === T_CURLY_OPEN || $kind === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            $modifiers = [];
            $doc = $class !== null && $depth > $class['depth'] + 1 ? $doc : null;
            continue;
        }
        if ($kind === '}') {
            $depth--;
            if ($class !== null && $depth === $class['depth']) {
                $class = null;
            }
            $modifiers = [];
            continue;
        }
        if ($kind === ';') {
            $modifiers = [];
            if ($class === null || $depth === $class['depth'] + 1) {
                $doc = null;
            }
            continue;
        }

        if ($class === null && in_array($kind, [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
            $modifiers[] = strtolower($t[1]);
            continue;
        }
        if ($class === null && in_array($kind, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $prev = $tokens[$i - 1] ?? null;
            if ($prev !== null && in_array($id($prev), [T_NEW, T_DOUBLE_COLON], true)) {
                continue;
            }
            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($id($tokens[$j]) === T_STRING) {
                    $name = $tokens[$j][1];
                    break;
                }
            }
            if ($name === null) {
                continue;
            }
            $fqcn = ltrim($namespace.'\\'.$name, '\\');
            $out[$fqcn] = [
                'kind' => strtolower($t[1]),
                'final' => in_array('final', $modifiers, true),
                'abstract' => in_array('abstract', $modifiers, true),
                'internal' => $doc !== null && preg_match('/@internal\b/', $doc) === 1,
                'file' => $path,
                'line' => $t[2],
                'methods' => [],
                'constants' => [],
                'properties' => [],
                'cases' => [],
            ];
            $class = ['fqcn' => $fqcn, 'depth' => $depth, 'kind' => strtolower($t[1])];
            $modifiers = [];
            $doc = null;
            // Skip to the body's brace; the brace handler raises the depth.
            for ($j = $i + 1; $j < $count && $text($tokens[$j]) !== '{'; $j++);
            $i = $j - 1;
            continue;
        }

        if ($class === null || $depth !== $class['depth'] + 1) {
            continue;
        }

        // Member level.
        if (in_array($kind, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR], true)) {
            $modifiers[] = strtolower($t[1]);
            continue;
        }
        $visibility = in_array('private', $modifiers, true) ? 'private' : (in_array('protected', $modifiers, true) ? 'protected' : 'public');
        $fq = $class['fqcn'];

        if ($kind === T_FUNCTION) {
            $j = $i + 1;
            if ($text($tokens[$j]) === '&') {
                $j++;
            }
            $name = $text($tokens[$j]);
            $methodLine = $line($j);
            // Parameters.
            for (; $j < $count && $text($tokens[$j]) !== '('; $j++);
            $params = [];
            $current = [];
            $paren = 0;
            for ($j++; $j < $count; $j++) {
                $tt = $text($tokens[$j]);
                if ($tt === '(' || $tt === '[' || $tt === '{' || $tt === '#[') {
                    $paren++;
                } elseif ($tt === ')' || $tt === ']' || $tt === '}') {
                    if ($paren === 0) {
                        break;
                    }
                    $paren--;
                }
                if ($tt === ',' && $paren === 0) {
                    $params[] = $current;
                    $current = [];
                    continue;
                }
                $current[] = $tokens[$j];
            }
            if ($current !== []) {
                $params[] = $current;
            }
            $parsed = [];
            foreach ($params as $param) {
                $parsed[] = param($param);
            }
            // Return type.
            $returns = null;
            if (($text($tokens[$j + 1] ?? '')) === ':') {
                $returns = '';
                for ($k = $j + 2; $k < $count && ! in_array($text($tokens[$k]), ['{', ';'], true); $k++) {
                    $returns .= $text($tokens[$k]);
                }
            }
            if ($visibility === 'public') {
                $out[$fq]['methods'][strtolower($name)] = [
                    'name' => $name,
                    'static' => in_array('static', $modifiers, true),
                    'abstract' => in_array('abstract', $modifiers, true) || $class['kind'] === 'interface',
                    'final' => in_array('final', $modifiers, true),
                    'internal' => $doc !== null && preg_match('/@internal\b/', $doc) === 1,
                    'line' => $methodLine,
                    'params' => $parsed,
                    'returns' => $returns,
                ];
            }
            $modifiers = [];
            $doc = null;
            // Jump past the parameter list; the body's braces are counted by the loop.
            $i = $j;
            continue;
        }
        if ($kind === T_CONST) {
            for ($j = $i + 1; $j < $count && $text($tokens[$j]) !== ';'; $j++) {
                if ($id($tokens[$j]) === T_STRING && $text($tokens[$j + 1] ?? '') === '=' && $visibility === 'public') {
                    $out[$fq]['constants'][] = $tokens[$j][1];
                }
            }
            $i = $j - 1;
            $modifiers = [];
            continue;
        }
        if ($kind === T_CASE && $class['kind'] === 'enum') {
            $out[$fq]['cases'][] = $text($tokens[$i + 1]);
            continue;
        }
        if ($kind === T_VARIABLE && $modifiers !== [] && $visibility === 'public') {
            $out[$fq]['properties'][] = ltrim($t[1], '$');
            for ($j = $i + 1; $j < $count && $text($tokens[$j]) !== ';'; $j++);
            $i = $j - 1;
            $modifiers = [];
            continue;
        }
    }

    return $out;
}

/** @param list<mixed> $tokens */
function param(array $tokens): array
{
    $type = '';
    $name = null;
    $variadic = false;
    $byRef = false;
    $optional = false;
    $promoted = false;
    $attribute = 0;
    foreach ($tokens as $t) {
        $id = is_array($t) ? $t[0] : $t;
        $text = is_array($t) ? $t[1] : $t;
        if ($id === T_DOC_COMMENT) {
            continue;
        }
        if ($id === T_ATTRIBUTE || ($attribute > 0 && $text === '[')) {
            $attribute++;

            continue;
        }
        if ($attribute > 0) {
            if ($text === ']') {
                $attribute--;
            }

            continue;
        }
        if ($name !== null) {
            if ($text === '=') {
                $optional = true;
                break;
            }

            continue;
        }
        if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
            $promoted = true;

            continue;
        }
        if ($id === T_ELLIPSIS) {
            $variadic = true;

            continue;
        }
        if ($text === '&' || $id === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG || $id === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
            $byRef = true;

            continue;
        }
        if ($id === T_VARIABLE) {
            $name = ltrim($text, '$');

            continue;
        }
        $type .= $text;
    }

    return ['name' => $name, 'type' => $type === '' ? null : $type, 'optional' => $optional || $variadic, 'variadic' => $variadic, 'byRef' => $byRef, 'promoted' => $promoted];
}
