<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use Illuminate\Console\Command;

/**
 * The OWNER's terminal path to a decision: approve or reject at any point
 * (an override), approve or reject a HITL initiation, or revoke. Refused unless
 * --owner is listed in `council.owners`. It exists for the owner at a terminal;
 * an agent must not run it, and the lab UI is the normal path.
 */
final class DecideCommand extends Command
{
    use Concerns;

    protected $signature = 'council:decide {ref} {decision : approve | reject | revoke | approve-initiation | reject-initiation} {--owner= : The owner\'s lab email (must be in council.owners)} {--reason= : Required} {--acknowledge=* : Vote ids of open conflicts, when approving over them}';

    protected $description = 'Owner decision on a Change (owners only; reason required)';

    public function handle(Ledger $ledger): int
    {
        $owner = (string) $this->option('owner');
        $owners = (array) $this->laravel['config']->get('council.owners', []);
        if ($owner === '' || ! in_array($owner, $owners, true)) {
            $this->error('--owner must be one of council.owners. This is the owner\'s command, not an agent\'s.');

            return 2;
        }
        $decision = (string) $this->argument('decision');
        $reason = trim((string) $this->option('reason'));
        if ($reason === '' && $decision !== 'approve-initiation') {
            $this->error('--reason is required.');

            return 2;
        }
        $change = $this->change($ledger, (string) $this->argument('ref'));
        if ($change === null) {
            return 1;
        }
        try {
            $change = match ($decision) {
                'approve', 'reject' => $ledger->override($change, $owner, $decision, $reason, array_map('intval', (array) $this->option('acknowledge'))),
                'revoke' => $ledger->revoke($change, $owner, $reason),
                'approve-initiation' => $ledger->approveInitiation($change, $owner, $reason === '' ? null : $reason),
                'reject-initiation' => $ledger->rejectInitiation($change, $owner, $reason),
                default => throw new Refused('invalid_request', 'decision must be approve, reject, revoke, approve-initiation or reject-initiation'),
            };
        } catch (Refused $e) {
            $this->error($e->getMessage().($e->context !== [] ? ' '.json_encode($e->context) : ''));

            return 1;
        }
        $this->info("{$change->ref}: {$change->state}");

        return 0;
    }
}
