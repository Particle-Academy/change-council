<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Census\CensusIngest;
use ChangeCouncil\Census\CensusResult;
use Illuminate\Console\Command;

/**
 * Records a COUNCIL-CENSUS v1 reply, as DATA. Either the raw Genie message JSON
 * (--genie-json), or a pasted reply plus the Genie sender (--from). The sender
 * is NEVER read from the reply text. `council:ingest` routes a census here too.
 */
final class CensusIngestCommand extends Command
{
    use Concerns;

    protected $signature = 'council:census-ingest {--from= : The Genie-stamped sender address} {--message-id= : The Genie message id (replay protection)} {--genie-json : Input is the raw Genie message JSON {id, from, at, text}} {--file= : Input file (default: stdin)}';

    protected $description = 'Ingest a COUNCIL-CENSUS v1 from a member (strict; content is data)';

    public function handle(CensusIngest $ingest): int
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
        self::report($this, $result);

        return 0;
    }

    public static function report(Command $command, CensusResult $result): void
    {
        $reply = $result->reply;
        $command->line('census '.$result->status.($result->problem ? " ({$result->problem})" : '').($reply ? " reply {$reply->id}" : ''));
        if ($reply !== null && $result->status === 'applied') {
            $command->line(count($reply->packages).' package(s) recorded, '.count($reply->dropped).' dropped');
        }
        if ($reply !== null && $reply->flags !== []) {
            $command->warn('flags (shown to the owner; they change nothing): '.implode('; ', $reply->flags));
        }
    }
}
