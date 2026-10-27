<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use Illuminate\Console\Command;

final class WithdrawCommand extends Command
{
    use Concerns;

    protected $signature = 'council:withdraw {ref} {--by= : The initiator\'s address}';

    protected $description = 'Withdraw a Change you initiated';

    public function handle(Ledger $ledger): int
    {
        $change = $this->change($ledger, (string) $this->argument('ref'));
        if ($change === null) {
            return 2;
        }
        try {
            $change = $ledger->withdraw($change, (string) $this->option('by'));
        } catch (Refused $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $this->info("{$change->ref}: {$change->state}");

        return 0;
    }
}
