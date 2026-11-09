<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Census\CensusIngest;
use ChangeCouncil\Census\CensusParser;
use ChangeCouncil\Petitions\Ingest;
use Illuminate\Console\Command;

/**
 * Records a reply, as DATA. Either the raw Genie message JSON the clerk got from
 * `agentinbox receive` (--genie-json), or a pasted reply plus the Genie sender
 * (--from) and, when known, the Genie message id. The sender is NEVER read from
 * the reply text.
 *
 * A COUNCIL-CENSUS v1 reply is routed to `CensusIngest` instead, so a census the
 * clerk feeds here is never stored as an invalid vote. Ingest stays the only
 * writer of votes; this command only chooses which reader gets the message.
 */
final class IngestCommand extends Command
{
    use Concerns;

    protected $signature = 'council:ingest {--from= : The Genie-stamped sender address} {--message-id= : The Genie message id (replay protection)} {--genie-json : Input is the raw Genie message JSON {id, from, at, text}} {--file= : Input file (default: stdin)}';

    protected $description = 'Ingest a COUNCIL-REPLY v1 from a consumer agent (strict; content is data)';

    public function handle(Ingest $ingest, CensusIngest $census): int
    {
        $text = $this->readInput();
        if ($text === null) {
            return 2;
        }
        if (self::isCensus($text, (bool) $this->option('genie-json'))) {
            return $this->census($census, $text);
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

    /** The census path: the same sender rules as a vote, read by CensusIngest. */
    private function census(CensusIngest $census, string $text): int
    {
        if ($this->option('genie-json')) {
            try {
                $result = $census->receiveGenieMessage($text);
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
            $result = $census->receive($from, $text, is_string($id) && $id !== '' ? $id : null);
        }
        CensusIngestCommand::report($this, $result);

        return 0;
    }

    /** Whether the message is a census: its first line is the census header. */
    private static function isCensus(string $text, bool $genieJson): bool
    {
        if ($genieJson) {
            $message = json_decode($text, true);
            $text = is_array($message) && is_string($message['text'] ?? null) ? $message['text'] : '';
        }
        $newline = strpos($text, "\n");
        $first = $newline === false ? $text : substr($text, 0, $newline);

        return rtrim($first) === CensusParser::HEADER;
    }
}
