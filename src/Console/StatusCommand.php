<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Http\Presenter;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Models\Change;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    use Concerns;

    protected $signature = 'council:status {ref? : One Change in detail} {--json : Print JSON}';

    protected $description = 'Show Changes and their state, or one Change in detail';

    public function handle(Ledger $ledger, Presenter $presenter): int
    {
        $ref = $this->argument('ref');
        if (is_string($ref) && $ref !== '') {
            $change = $this->change($ledger, $ref);
            if ($change === null) {
                return 1;
            }
            $detail = $presenter->detail($change);
            if ($this->option('json')) {
                $this->emitJson($detail);

                return 0;
            }
            $c = $detail['change'];
            $this->line("{$c['ref']}  {$c['state']}  {$c['title']}");
            foreach ($detail['rounds'] as $round) {
                $this->line("  round {$round['number']} {$round['approach_digest']} closes {$round['closes_at']}".($round['closed_at'] ? " (closed: {$round['outcome']})" : ''));
                foreach ($round['petitions'] as $p) {
                    $this->line("    {$p['agent_address']} [{$p['tier']}] delivery={$p['delivery']} outcome=".($p['outcome'] ?? '-')." policy={$p['effective_silence_policy']}");
                }
            }

            return 0;
        }
        $rows = Change::query()->orderByDesc('created_at')->limit(100)->get()->map(fn (Change $c) => $presenter->summary($c))->all();
        if ($this->option('json')) {
            $this->emitJson($rows);

            return 0;
        }
        foreach ($rows as $r) {
            $this->line(sprintf('%-16s %-18s %s', $r['ref'], $r['state'], $r['title']));
        }

        return 0;
    }
}
