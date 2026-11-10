<?php

declare(strict_types=1);

namespace ChangeCouncil\Census;

/**
 * Which package names are first-party in a census: host configuration
 * (`council.census.first_party`), per ecosystem.
 *
 *     'first_party' => [
 *         'composer' => ['patterns' => ['particle-academy/*']],
 *         'npm' => ['patterns' => ['@particle-academy/*']],
 *         'pypi' => ['patterns' => ['prism-ai-*'], 'names' => ['prism-conformance']],
 *     ]
 *
 * A pattern is NOT a regex. It is an exact name, or a literal prefix followed
 * by ONE trailing `*`. Matching is `str_starts_with()` / `===`, so nothing from
 * config is ever compiled and run against member input: there is no regex to
 * be slow. Each entry is validated when this is built (the provider builds it
 * at boot), and anything invalid throws, naming the entry; nothing is ever
 * silently ignored.
 *
 * With no `first_party` key the rule is {@see self::DEFAULTS}, Fancy's. When
 * the key is set it is the whole rule: an ecosystem it leaves out has no
 * first-party packages.
 */
final class FirstParty
{
    /** @var array<string, array{patterns: list<string>, names: list<string>}> */
    public const DEFAULTS = [
        'composer' => ['patterns' => ['particle-academy/*'], 'names' => []],
        'npm' => ['patterns' => ['@particle-academy/*'], 'names' => []],
        'pypi' => ['patterns' => ['fancy-*'], 'names' => []],
    ];

    public const DEFAULT_PUBLISHER = 'Particle Academy';

    public const MAX_PATTERNS = 50;

    public const MAX_NAMES = 200;

    public const MAX_PUBLISHER = 100;

    private const KEYS = ['patterns', 'names'];

    /**
     * The characters a pattern's literal part may hold, per ecosystem. PyPI is
     * compared after PEP 503 normalisation, so its patterns must already be in
     * that form (lower case, `-` only).
     */
    private const PATTERN_CHARS = [
        'composer' => '#^[a-z0-9._/-]+$#D',
        'npm' => '#^[a-z0-9@._/-]+$#D',
        'pypi' => '#^[a-z0-9-]+$#D',
    ];

    private static ?self $defaults = null;

    /**
     * @param  array<string, array{prefixes: list<string>, names: list<string>, describe: list<string>}>  $rules
     */
    private function __construct(private readonly array $rules, private readonly string $publisher) {}

    public static function defaults(): self
    {
        return self::$defaults ??= self::fromConfig(null, null);
    }

    /**
     * @throws \InvalidArgumentException naming the first invalid entry
     */
    public static function fromConfig(mixed $firstParty, mixed $publisher): self
    {
        $key = 'council.census.first_party';
        if ($firstParty === null) {
            $firstParty = self::DEFAULTS;
        }
        if (! is_array($firstParty) || ($firstParty !== [] && array_is_list($firstParty))) {
            throw self::invalid($key, 'must be an array keyed by ecosystem ('.implode(', ', CensusParser::ECOSYSTEMS).')');
        }
        foreach (array_keys($firstParty) as $ecosystem) {
            if (! in_array($ecosystem, CensusParser::ECOSYSTEMS, true)) {
                throw self::invalid($key.'.'.$ecosystem, 'is not an ecosystem; use one of '.implode(', ', CensusParser::ECOSYSTEMS));
            }
        }

        $rules = [];
        foreach (CensusParser::ECOSYSTEMS as $ecosystem) {
            $rules[$ecosystem] = self::ecosystem($key.'.'.$ecosystem, $ecosystem, $firstParty[$ecosystem] ?? []);
        }

        $publisher ??= self::DEFAULT_PUBLISHER;
        if (! is_string($publisher) || trim($publisher) === '' || mb_strlen($publisher) > self::MAX_PUBLISHER
            || ! mb_check_encoding($publisher, 'UTF-8') || preg_match('#[\x00-\x1F\x7F]|\xC2[\x80-\x9F]#', $publisher) === 1) {
            throw self::invalid('council.census.publisher', 'must be a single line of 1 to '.self::MAX_PUBLISHER.' characters');
        }

        return new self($rules, $publisher);
    }

    public function isFirstParty(string $ecosystem, string $name): bool
    {
        $rule = $this->rules[$ecosystem] ?? null;
        if ($rule === null) {
            return false;
        }
        if (in_array($name, $rule['names'], true)) {
            return true;
        }
        foreach ($rule['prefixes'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Per ecosystem, the patterns then the names, as the request text shows them.
     *
     * @return array<string, list<string>>
     */
    public function describe(): array
    {
        return array_map(fn (array $rule) => $rule['describe'], $this->rules);
    }

    public function publisher(): string
    {
        return $this->publisher;
    }

    /** A name for the request's example reply: the first entry, `*` shown as `...`. */
    public function example(): array
    {
        foreach ($this->describe() as $ecosystem => $entries) {
            if ($entries !== []) {
                return [$ecosystem, str_ends_with($entries[0], '*') ? substr($entries[0], 0, -1).'...' : $entries[0]];
            }
        }

        return ['composer', 'vendor/package'];
    }

    /** @return array{prefixes: list<string>, names: list<string>, describe: list<string>} */
    private static function ecosystem(string $key, string $ecosystem, mixed $rule): array
    {
        if (! is_array($rule) || ($rule !== [] && array_is_list($rule))) {
            throw self::invalid($key, 'must be an array with `patterns` and/or `names`');
        }
        foreach (array_keys($rule) as $k) {
            if (! in_array($k, self::KEYS, true)) {
                throw self::invalid($key.'.'.$k, 'is not a known key; use `patterns` and/or `names`');
            }
        }

        $patterns = self::list($key.'.patterns', $rule['patterns'] ?? [], self::MAX_PATTERNS);
        $names = self::list($key.'.names', $rule['names'] ?? [], self::MAX_NAMES);

        $prefixes = [];
        $exact = [];
        foreach ($patterns as $i => $pattern) {
            $at = $key.'.patterns.'.$i;
            if (! is_string($pattern) || $pattern === '' || strlen($pattern) > CensusParser::MAX_NAME) {
                throw self::invalid($at, 'must be a non-empty string of at most '.CensusParser::MAX_NAME.' characters');
            }
            $star = strpos($pattern, '*');
            $literal = $star === false ? $pattern : substr($pattern, 0, -1);
            if ($star !== false && $star !== strlen($pattern) - 1) {
                throw self::invalid($at, 'may hold one `*`, only at the end (a prefix like `prism-ai-*`); it is not a regex');
            }
            if ($literal === '') {
                throw self::invalid($at, 'needs a literal prefix before `*`; a bare `*` would make every package first-party');
            }
            if (preg_match(self::PATTERN_CHARS[$ecosystem], $literal) !== 1) {
                throw self::invalid($at, 'may hold only '.($ecosystem === 'pypi' ? 'lower-case letters, digits and `-` (the PEP 503 normalised form)' : 'lower-case letters, digits and `@ . _ / -`').', plus one trailing `*`; it is not a regex');
            }
            if ($star === false) {
                if (! CensusParser::validName($ecosystem, $literal)) {
                    throw self::invalid($at, 'is not a valid '.$ecosystem.' package name');
                }
                $exact[] = $literal;
            } else {
                $prefixes[] = $literal;
            }
        }
        foreach ($names as $i => $name) {
            $at = $key.'.names.'.$i;
            if (! is_string($name) || $name === '' || strlen($name) > CensusParser::MAX_NAME
                || ! CensusParser::validName($ecosystem, $name)
                || ($ecosystem === 'pypi' && CensusParser::pypiName($name) !== $name)) {
                throw self::invalid($at, 'must be a valid '.$ecosystem.' package name'.($ecosystem === 'pypi' ? ' in PEP 503 normalised form' : ''));
            }
            $exact[] = $name;
        }

        return [
            'prefixes' => $prefixes,
            'names' => $exact,
            'describe' => [...$patterns, ...$names],
        ];
    }

    private static function list(string $key, mixed $value, int $max): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($key, 'must be a list');
        }
        if (count($value) > $max) {
            throw self::invalid($key, 'holds '.count($value).' entries; at most '.$max);
        }

        return $value;
    }

    private static function invalid(string $key, string $why): \InvalidArgumentException
    {
        return new \InvalidArgumentException("change-council: {$key} {$why}. The census first-party rule is refused rather than ignored.");
    }
}
