<?php

declare(strict_types=1);

namespace ChangeCouncil\Consumers;

use ChangeCouncil\Models\Consumer;

/**
 * The known consumers, from `.ai/plans/change-council.md` section 3.3 and the
 * MOIC addendum (2026-10-27). Seed data, NOT the registry: rows are `inferred`
 * (or `declared` where the consumer said so through Prism) and have no census,
 * so a Change with no required voters escalates until the census runs.
 *
 * Every row defaults to `silence_policy: escalate`. Re-seeding adds missing
 * rows and missing packages; it never touches an owner-set silence policy, a
 * whitelist flag or a census.
 */
final class ConsumerSeed
{
    private const C = 'composer';

    private const N = 'npm';

    /** @return list<array{address: string, source: string, internal?: bool, packages: list<array>, watches?: list<array>, notes: string}> */
    public static function rows(): array
    {
        $c = fn (string $name, string $constraint = '*') => ['ecosystem' => self::C, 'name' => 'particle-academy/'.$name, 'constraint' => $constraint];
        $n = fn (string $name, string $constraint = '*') => ['ecosystem' => self::N, 'name' => '@particle-academy/'.$name, 'constraint' => $constraint];

        return [
            [
                'address' => 'moic@moic-suite',
                'source' => 'declared',
                'packages' => [
                    // MOIC's Prism set, declared via Prism 2026-10-27: PHP only, exact pins.
                    $c('prism', '0.131.0'), $c('prism-harness', '0.13.0'), $c('prism-opentelemetry', '0.5.2'), $c('prism-perplexity', '0.3.0'),
                    // Fancy, inferred (memory + kit-changes.md + the MOIC editing plans).
                    $c('fancy-flow-php'), $n('fancy-flow'), $n('react-fancy'), $n('fancy-slides'), $n('fancy-inertia'), $n('fancy-app-update'),
                    $c('fine-print'), $c('fancy-cms'), $c('holy-sheet'), $c('last-word'),
                ],
                // "about to be adopted": informed until MOIC declares it.
                'watches' => [['ecosystem' => self::C, 'name' => 'particle-academy/prism-memory']],
                'notes' => 'Prism set declared by MOIC via Prism (2026-10-27), exact pins on main and dev. prism-memory to be added when adopted. Fancy set inferred.',
            ],
            [
                'address' => 'tynn@tynn',
                'source' => 'inferred',
                'packages' => [$n('react-fancy'), $n('agent-integrations'), $n('fancy-term-host'), $n('fancy-pty'), $n('prism-acp'), $n('prism-mcp-server-ts'), $n('prism-harness-ts'), $n('dark-slide'), $n('holy-sheet')],
                'notes' => 'Genie 2. Prism TS set per Prism (2026-10-27); no prism PHP. Genie plugins generate with dark-slide and holy-sheet.',
            ],
            [
                'address' => 'miapa@maipa',
                'source' => 'inferred',
                'packages' => [$n('react-fancy'), $n('agent-integrations'), $n('prism-acp')],
                'notes' => 'Python backend.',
            ],
            [
                'address' => 'weaver@weaver',
                'source' => 'inferred',
                'packages' => [$c('prism-harness')],
                'notes' => 'Owns fancy-connector-core (a producer). Consumes prism-harness per Prism.',
            ],
            [
                'address' => 'guardcard@guardcard-net',
                'source' => 'inferred',
                'packages' => [$n('react-fancy'), $c('laravel-jobs'), $n('teachers-aid-ui')],
                'notes' => 'Uses react-fancy useFancyForm. Reachable as of 2026-10-27.',
            ],
            [
                'address' => 'tribeshare@tribetrip',
                'source' => 'inferred',
                'packages' => [$n('react-fancy'), $c('fine-print')],
                'notes' => 'Owner limits work FOR it; still informed of breaking changes.',
            ],
            // Ours: derived from lockfiles, covered by the dogfood rule, never petitioned.
            ['address' => 'px-ui-sandbox', 'source' => 'lockfile', 'internal' => true, 'packages' => [], 'notes' => 'The showcase; dogfoods every release.'],
            ['address' => 'fancy-starter-kit', 'source' => 'lockfile', 'internal' => true, 'packages' => [], 'notes' => 'Starter kit lockstep rule.'],
            ['address' => 'fancy-labs', 'source' => 'lockfile', 'internal' => true, 'packages' => [], 'notes' => 'The lab.'],
        ];
    }

    /** @return array{created: int, updated: int} */
    public static function seed(): array
    {
        $created = 0;
        $updated = 0;
        foreach (self::rows() as $row) {
            $existing = Consumer::query()->where('address', $row['address'])->first();
            if ($existing === null) {
                Consumer::query()->create([
                    'address' => $row['address'],
                    'packages' => $row['packages'],
                    'watches' => $row['watches'] ?? [],
                    'silence_policy' => 'escalate',
                    'whitelisted' => false,
                    'internal' => $row['internal'] ?? false,
                    'source' => $row['source'],
                    'census_at' => null,
                    'notes' => $row['notes'],
                ]);
                $created++;

                continue;
            }
            $packages = $existing->packages;
            $known = array_map(fn (array $p) => $p['ecosystem'].':'.$p['name'], $packages);
            foreach ($row['packages'] as $p) {
                if (! in_array($p['ecosystem'].':'.$p['name'], $known, true)) {
                    $packages[] = $p;
                }
            }
            if ($packages !== $existing->packages) {
                $existing->forceFill(['packages' => $packages])->save();
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }
}
