<?php

declare(strict_types=1);

namespace ChangeCouncil\Petitions;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Models\Vote;
use ChangeCouncil\Support\GenieMessage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Takes a reply exactly as the clerk received it and records it as DATA.
 *
 * Identity is the Genie-stamped `from`, passed by the caller (an agent id is
 * resolved through the host's map, {@see GenieMessage}), never a field in the
 * reply. The reply is bound to its round by nonce and digest. An ACCEPTED
 * message id makes a replay a no-op; a refusal does not consume it. Every reply is stored with its raw text,
 * whatever its fate; only a `counted` (or, for a consumer on catch-up, `late`)
 * vote is ever read by the tally, and only by its structured fields. Nothing in
 * a reply is executed, merged or copied into an approach.
 */
final class Ingest
{
    /** @param array<string, string> $agents the host's Genie agent id => address map ({@see GenieMessage}) */
    public function __construct(private readonly ConnectionInterface $db, private readonly AuditLog $audit, private readonly Ledger $ledger, private readonly array $agents = []) {}

    /** The raw message JSON from `agentinbox receive`: {id, from, text, sentAt} (or `at`). */
    public function receiveGenieMessage(string $json): IngestResult
    {
        $message = GenieMessage::read($json, $this->agents);

        return $this->receive($message['from'], $message['text'], $message['id'], $message['at']);
    }

    public function receive(string $from, string $text, ?string $messageId = null, ?string $at = null): IngestResult
    {
        $from = GenieMessage::resolve($from, $this->agents);
        // A pasted reply without a Genie id still gets a stable one, so pasting
        // the same reply twice is a replay too.
        $messageId ??= 'sha256:'.hash('sha256', $from."\n".$text);

        return $this->db->transaction(function () use ($from, $text, $messageId, $at): IngestResult {
            // Replay keys on ACCEPTED votes only. A refusal (wrong sender, bad
            // shape) is kept for audit but does not consume the message id, so
            // the same genuine message can be re-ingested once the sender is
            // fixed. The same message from the same sender again is a replay.
            $accepted = Vote::query()->where('accepted_message_id', $messageId)->first();
            if ($accepted !== null) {
                return new IngestResult('duplicate', $accepted);
            }
            $refused = Vote::query()->where('genie_message_id', $messageId)->where('from_address', $from)->latest('id')->first();
            if ($refused !== null) {
                return new IngestResult('duplicate', $refused);
            }
            $flags = Sanitiser::flags($text);
            $parsed = ReplyParser::parse($text);
            if (! $parsed['ok']) {
                return $this->store($messageId, $from, $text, $flags, $at, null, 'invalid', $parsed['problem']);
            }
            $reply = $parsed['reply'];

            $petition = Petition::query()->where('nonce', $reply['nonce'])->first();
            if ($petition === null) {
                return $this->store($messageId, $from, $text, $flags, $at, null, 'unsolicited', 'unknown_nonce');
            }
            if ($petition->agent_address !== $from) {
                // The nonce was someone else's petition: refused, never counted.
                // An agent id nobody mapped gets its own code, so the clerk
                // knows to map it rather than suspect the member.
                return $this->store($messageId, $from, $text, $flags, $at, null, 'unsolicited', GenieMessage::isAgentId($from) ? 'unmapped_agent' : 'sender_mismatch');
            }
            $change = Change::query()->findOrFail($petition->change_id);
            $round = $petition->round;
            $problem = match (true) {
                $reply['change'] !== $change->ref => 'change_mismatch',
                $reply['round'] !== $round->number => 'round_mismatch',
                ! hash_equals($round->approach_digest, $reply['digest']) => 'digest_mismatch',
                default => null,
            };
            if ($problem !== null) {
                return $this->store($messageId, $from, $text, $flags, $at, $petition, 'invalid', $problem);
            }
            if ($petition->withdrawn_at !== null) {
                // Its member was removed from the council: kept as data, never counted.
                return $this->store($messageId, $from, $text, $flags, $at, $petition, 'invalid', 'petition_withdrawn');
            }

            $open = in_array($change->state, States::OPEN_ROUND, true) && $change->current_round === $round->number && $round->closed_at === null;
            if (! $open && ! $petition->catch_up) {
                return $this->store($messageId, $from, $text, $flags, $at, $petition, 'invalid', 'round_closed');
            }

            $status = $open ? 'counted' : 'late';
            if ($open) {
                Vote::query()->where('petition_id', $petition->id)->where('status', 'counted')->update(['status' => 'superseded']);
            }
            $result = $this->store($messageId, $from, $text, $flags, $at, $petition, $status, null, $reply);
            if ($open) {
                $petition->forceFill(['outcome' => $reply['verdict']])->save();
                $this->audit->append($change->id, $from, 'vote.recorded', [
                    'petition' => $petition->id, 'vote' => $result->vote->id, 'round' => $round->number,
                    'verdict' => $reply['verdict'], 'worst' => $result->vote->worstSeverity(), 'flags' => count($flags),
                ]);
            } else {
                $this->ledger->recordLate($change, $petition, $result->vote);
            }

            return $result;
        });
    }

    private function store(string $messageId, string $from, string $text, array $flags, ?string $at, ?Petition $petition, string $status, ?string $problem, ?array $reply = null): IngestResult
    {
        $vote = Vote::query()->create([
            'petition_id' => $petition?->id,
            'genie_message_id' => $messageId,
            // Unique: set only on an accepted vote, so a replay of one is refused
            // by the database too. Superseding a vote keeps it.
            'accepted_message_id' => $status === 'counted' || $status === 'late' ? $messageId : null,
            'from_address' => $from,
            'verdict' => $reply['verdict'] ?? null,
            'impact' => $reply === null ? [] : array_map(fn (array $row) => [
                'app' => Sanitiser::clean($row['app']),
                'paths' => array_map(Sanitiser::clean(...), $row['paths']),
                'severity' => $row['severity'],
                'note' => Sanitiser::clean((string) ($row['note'] ?? '')),
            ], $reply['impact']),
            'alternative' => isset($reply['alternative']) ? Sanitiser::clean($reply['alternative']) : null,
            'reason' => isset($reply['reason']) ? Sanitiser::clean($reply['reason']) : null,
            'raw_text' => $text,
            'flags' => $flags,
            'received_at' => self::when($at),
            'status' => $status,
            'problem' => $problem,
        ]);
        if ($status !== 'counted' && $status !== 'late') {
            $this->audit->append($petition?->change_id, $from, 'vote.'.$status, ['vote' => $vote->id, 'problem' => $problem, 'petition' => $petition?->id]);
        }

        return new IngestResult($status, $vote, $problem);
    }

    /** Genie's `at`, or now when absent or unreadable (it is metadata, not a vote field). */
    private static function when(?string $at): Carbon
    {
        if ($at !== null) {
            try {
                return Carbon::parse($at);
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }
}
