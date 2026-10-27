<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Records\DecisionRecords;
use Illuminate\Console\Command;

/** Writes the signed decision record into a package repo, to commit before tagging. */
final class ExportCommand extends Command
{
    use Concerns;

    protected $signature = 'council:export {ref} {--to= : Path to the package repo}';

    protected $description = 'Write .council/decisions/<ref>.json (Ed25519-signed) into a repo';

    public function handle(Ledger $ledger, DecisionRecords $records): int
    {
        $to = (string) $this->option('to');
        if ($to === '') {
            $this->error('--to=<repo path> is required.');

            return 2;
        }
        $change = $this->change($ledger, (string) $this->argument('ref'));
        if ($change === null) {
            return 1;
        }
        try {
            $path = $records->export($change, $to);
        } catch (Refused $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info("Wrote {$path}. Commit it before tagging.");

        return 0;
    }
}
