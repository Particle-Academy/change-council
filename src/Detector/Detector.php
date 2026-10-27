<?php

declare(strict_types=1);

namespace ChangeCouncil\Detector;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The PHP entry point to the detector. It RUNS `council/check.mjs`; it does not
 * mirror it. One implementation means the pre-push hook, CI on public repos,
 * Prism's Actions-only flow and this artisan command report byte-identical
 * findings and fingerprints. A PHP port would be a second detector that drifts
 * from the first, and fingerprints that differ between the two would make an
 * approved Change fail to "cover" its own finding.
 *
 * The cost: the lab needs Node on PATH. Both labs build with Vite, so it is.
 */
final class Detector
{
    public function __construct(private readonly string $node, private readonly ?string $script = null) {}

    public function script(): string
    {
        return $this->script ?? dirname(__DIR__, 2).'/council/check.mjs';
    }

    /** @param array{from?: ?string, to?: ?string, release_notes?: ?string} $options */
    public function run(string $repo, array $options = []): array
    {
        $args = [$this->node, $this->script(), '--repo', $repo, '--json'];
        foreach (['from' => '--from', 'to' => '--to', 'release_notes' => '--release-notes'] as $key => $flag) {
            if (isset($options[$key]) && $options[$key] !== '') {
                $args[] = $flag;
                $args[] = $options[$key];
            }
        }
        $process = new Process($args, null, null, null, 300);
        $process->run();
        $report = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($report) || ($report['schema'] ?? null) !== 'change-council/report.v1') {
            throw new RuntimeException('The detector did not produce a report (exit '.$process->getExitCode().'): '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $report;
    }
}
