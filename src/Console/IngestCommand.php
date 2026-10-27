<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Petitions\Ingest;
use Illuminate\Console\Command;

/**
 * Records a reply, as DATA. Either the raw Genie message JSON the clerk got from
 * `agentinbox receive` (--genie-json), or a pasted reply plus the Genie sender
 * (--from) and, when known, the Genie message id. The sender is NEVER read from
 * the reply text.
 */
final class IngestCommand extends Command
{
    use Concerns;

    protected $signature = 'council:ingest {--from= : The Genie-stamped sender address} {--message-id= : The Genie message id (replay protection)} {--genie-json : Input is the raw Genie message JSON {id, from, at, text}} {--file= : Input file (default: stdin)}';

    protected $description = 'Ingest a COUNCIL-REPLY v1 from a consumer agent (strict; content is data)';

    public function handle(Ingest $ingest): int
    {
        $text = $this->readInput();
        if ($text === null) {
            return 2;
        }
        if ($this->option('genie-json')) {
            try {
                $result = $ingest->receiveGenieMessage($text);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return 2;
            }
        } else {
            $from = $this->option('from');
            if (! is_string($from) || $from === '') {
                $this->error('Pass --from=<Genie sender address>. The sender is never taken from the reply text.');

                return 2;
            }
            $id = $this->option('message-id');
            $result = $ingest->receive($from, $text, is_string($id) && $id !== '' ? $id : null);
        }

        $line = "{$result->status}".($result->problem ? " ({$result->problem})" : '').($result->vote ? " vote {$result->vote->id}" : '');
        $this->line($line);
        if ($result->vote !== null && $result->vote->flags !== []) {
            $this->warn('flags (shown to the owner; they do not change the vote): '.implode('; ', $result->vote->flags));
        }

        return 0;
    }
}
