<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Laravel\CouncilServiceProvider;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Petitions\PetitionText;
use Illuminate\Console\Command;

/**
 * The clerk's outbox. The ledger never calls Genie: this lists the petitions
 * still queued, with the exact text, for the clerk agent to send with its own
 * `agentinbox send`. The clerk then records each raw tool result with
 * --mark-sent=<petition id> --result='<raw JSON>'.
 *
 * --supersede-stale records petitions an older release left `queued` after
 * their round closed (or their Change was decided or withdrawn, or their
 * member was removed) as `superseded`, each audited. Running it again
 * changes nothing. No row is deleted.
 */
final class PetitionsCommand extends Command
{
    use Concerns;

    protected $signature = 'council:petitions {--json : Print JSON} {--mark-sent= : Petition id to record a send result for} {--result= : The raw agentinbox send tool result (JSON)} {--supersede-stale : Record every queued petition that can never be sent as superseded (idempotent)}';

    protected $description = 'List queued petitions for the clerk to send, or record a send result';

    public function handle(Ledger $ledger): int
    {
        $id = $this->option('mark-sent');
        if ($id !== null) {
            $result = $this->option('result');
            $petition = Petition::query()->find((int) $id);
            if ($petition === null || ! is_string($result) || $result === '') {
                $this->error('--mark-sent needs an existing petition id and --result=<raw tool JSON>.');

                return 2;
            }
            $petition = $ledger->markSent($petition, $result);
            $this->info("petition {$petition->id} to {$petition->agent_address}: {$petition->delivery}".($petition->delivery_detail ? " ({$petition->delivery_detail})" : ''));

            return 0;
        }

        if ($this->option('supersede-stale')) {
            $ids = $ledger->supersedeStale();
            $this->info(count($ids).' stale petition(s) superseded.');

            return 0;
        }

        $labUrl = (string) $this->laravel['config']->get('council.lab_url');
        $out = [];
        // Petition::sendable() is the one definition; the page counts the same set.
        foreach (Petition::query()->sendable()->orderBy('id')->get() as $petition) {
            $change = Change::query()->findOrFail($petition->change_id);
            $round = $petition->round;
            $out[] = ['petition' => $petition->id, 'change' => $change->ref, 'round' => $round->number, 'to' => $petition->agent_address, 'tier' => $petition->tier, 'text' => PetitionText::render($change, $round, $petition, $labUrl)];
        }
        if ($this->option('json')) {
            $this->emitJson($out);

            return 0;
        }
        if ($out === []) {
            $this->info('No queued petitions.');

            return 0;
        }
        foreach ($out as $p) {
            $this->line("== petition {$p['petition']} -> {$p['to']} ({$p['change']} round {$p['round']}, {$p['tier']})");
            $this->line($p['text']);
        }
        $this->line('Send each with agentinbox send, then: php artisan council:petitions --mark-sent=<id> --result=\'<raw tool JSON>\'');

        return 0;
    }
}
