<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Consumers\ConsumerSeed;
use Illuminate\Console\Command;

final class SeedConsumersCommand extends Command
{
    protected $signature = 'council:seed-consumers';

    protected $description = 'Seed the known consumer agents (idempotent; never overwrites an owner-set silence policy)';

    public function handle(): int
    {
        $result = ConsumerSeed::seed();
        $this->info("consumers: {$result['created']} created, {$result['updated']} updated");

        return 0;
    }
}
