<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use Illuminate\Console\Command;

final class ReviseCommand extends Command
{
    use Concerns;

    protected $signature = 'council:revise {ref} {--by= : The initiator\'s address} {--file= : The revised approach (default: stdin)}';

    protected $description = 'Answer blocking conflicts with a revised approach: opens round 2';

    public function handle(Ledger $ledger): int
    {
        $change = $this->change($ledger, (string) $this->argument('ref'));
        $approach = $this->readInput();
        if ($change === null || $approach === null) {
            return 2;
        }
        try {
            $change = $ledger->revise($change, $approach, (string) $this->option('by'));
        } catch (Refused $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info("{$change->ref}: {$change->state}");

        return 0;
    }
}
