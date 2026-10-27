<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use Illuminate\Console\Command;

/**
 * Proposes a Change from JSON: {title, summary, approach, classes[], packages[],
 * evidence?, links?, source, initiator, pros?, cons?}. A whitelisted initiation
 * opens round 1 at once; anything else waits for the owner.
 */
final class ProposeCommand extends Command
{
    use Concerns;

    protected $signature = 'council:propose {--file= : JSON file (default: stdin)} {--json : Print JSON}';

    protected $description = 'Propose a Change (whitelisted initiators open round 1; everyone else goes to the owner first)';

    public function handle(Ledger $ledger): int
    {
        $text = $this->readInput();
        $input = $text === null ? null : json_decode($text, true);
        if (! is_array($input)) {
            $this->error('Expected a JSON object. See docs/petitions.md for the fields.');

            return 2;
        }
        try {
            $change = $ledger->propose($input);
        } catch (Refused $e) {
            $this->error($e->getMessage());

            return 1;
        }
        if ($this->option('json')) {
            $this->emitJson(['ref' => $change->ref, 'state' => $change->state, 'whitelisted' => $change->whitelisted]);

            return 0;
        }
        $this->info("{$change->ref}: {$change->state}");
        $this->line($change->state === 'awaiting_owner'
            ? 'Waiting for the owner (HITL). No petition goes out until the owner approves it in the lab.'
            : 'Round 1 is open. Run council:petitions to list the petitions to send.');

        return 0;
    }
}
