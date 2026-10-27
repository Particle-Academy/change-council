<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Audit\AuditLog;
use Illuminate\Console\Command;

/** Recomputes the audit hash chain. Exits 1 on any break. */
final class VerifyLogCommand extends Command
{
    protected $signature = 'council:verify-log';

    protected $description = 'Verify the append-only audit log hash chain';

    public function handle(AuditLog $audit): int
    {
        $result = $audit->verify();
        if ($result['ok']) {
            $this->info("audit log OK: {$result['checked']} events, head {$result['head']}");

            return 0;
        }
        $this->error("audit log BROKEN at seq {$result['seq']}: {$result['problem']} ({$result['checked']} events verified before it)");

        return 1;
    }
}
