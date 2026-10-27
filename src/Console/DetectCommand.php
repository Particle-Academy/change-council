<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Detector\Detector;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use Illuminate\Console\Command;

/**
 * Runs the shared detector (`council/check.mjs`, the same file CI runs) on a
 * repo and optionally opens a Change from its major findings, with the full
 * report as evidence. Report-only: it never fails because of what it found.
 */
final class DetectCommand extends Command
{
    use Concerns;

    protected $signature = 'council:detect {--repo=. : The package repo} {--from= : Previous ref (default: previous v* tag)} {--to=HEAD} {--release-notes= : Release notes file}
        {--json : Print the report JSON}
        {--propose : Open a Change from the major findings}
        {--initiator= : Your Genie address (with --propose)} {--source=agent_finding} {--package=* : ecosystem:name (with --propose)}
        {--title=} {--approach= : The proposed approach (with --propose)} {--pros=} {--cons=}';

    protected $description = 'Run the change detector (report-only) and optionally propose a Change from it';

    public function handle(Detector $detector, Ledger $ledger): int
    {
        try {
            $report = $detector->run((string) $this->option('repo'), ['from' => $this->option('from'), 'to' => $this->option('to'), 'release_notes' => $this->option('release-notes')]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        if (! $this->option('propose')) {
            if ($this->option('json')) {
                $this->emitJson($report);
            } else {
                $this->line("{$report['summary']['major']} major, {$report['summary']['minor']} minor, {$report['summary']['info']} info, {$report['summary']['waived']} waived");
                foreach ($report['findings'] as $f) {
                    if ($f['severity'] === 'major' && empty($f['waived'])) {
                        $this->line("  [{$f['class']}] {$f['subject']}");
                    }
                }
            }

            return 0;
        }

        $majors = array_values(array_filter($report['findings'], fn (array $f) => $f['severity'] === 'major' && empty($f['waived'])));
        if ($majors === []) {
            $this->info('No major findings; nothing to propose. (The detector is a floor: behaviour changes are on you.)');

            return 0;
        }
        $packages = [];
        foreach ((array) $this->option('package') as $spec) {
            [$ecosystem, $name] = array_pad(explode(':', (string) $spec, 2), 2, '');
            if ($ecosystem !== '' && $name !== '') {
                $packages[] = ['ecosystem' => $ecosystem, 'name' => $name];
            }
        }
        $classes = array_values(array_unique(array_column($majors, 'class')));
        try {
            $change = $ledger->propose([
                'title' => (string) ($this->option('title') ?: 'Detected: '.implode(', ', $classes)),
                'summary' => implode("\n", array_map(fn (array $f) => "[{$f['class']}] {$f['subject']}", $majors)),
                'approach' => (string) $this->option('approach'),
                'classes' => $classes,
                'packages' => $packages,
                'evidence' => ['detector' => $report['detector'], 'from' => $report['from'], 'to' => $report['to'], 'to_sha' => $report['toSha'], 'findings' => $report['findings']],
                'links' => array_filter(['from' => $report['from'], 'commit' => $report['toSha']]),
                'source' => (string) $this->option('source'),
                'initiator' => (string) $this->option('initiator'),
                'pros' => $this->option('pros'),
                'cons' => $this->option('cons'),
            ]);
        } catch (Refused $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info("{$change->ref}: {$change->state} (".count($majors).' major finding(s) as evidence)');

        return 0;
    }
}
