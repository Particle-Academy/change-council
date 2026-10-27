<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Ledger\Ledger;
use Illuminate\Console\Command;

/** Closes due rounds. Schedule it (every 15 minutes is plenty). */
final class TallyCommand extends Command
{
    use Concerns;

    protected $signature = 'council:tally {--json : Print JSON}';

    protected $description = 'Tally open rounds: close the due ones, extend silent ones once, escalate per silence policy';

    public function handle(Ledger $ledger): int
    {
        $results = $ledger->tally();
        if ($this->option('json')) {
            $this->emitJson($results);

            return 0;
        }
        foreach ($results as $r) {
            $this->line("{$r['ref']}: {$r['action']} -> {$r['state']}");
        }
        if ($results === []) {
            $this->line('No open rounds.');
        }

        return 0;
    }
}
