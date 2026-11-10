<?php

declare(strict_types=1);

namespace ChangeCouncil\Census;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Models\CensusReply;
use ChangeCouncil\Models\CensusRequest;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Petitions\Sanitiser;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Takes a census reply exactly as the clerk received it and records it as DATA.
 * The ONLY writer of a census: of `source = census`, `census_at`, and the
 * `packages` a census replaces.
 *
 * Order, as Petitions\Ingest: replay check, size cap, flags, strict parse
 * (`CensusParser`), nonce lookup, sender match (the Genie-stamped `from`,
 * never a field in the text), already-answered check, member-removed check,
 * then apply. Every reply is stored with its raw text, whatever its fate.
 *
 * Applying REPLACES the member's `packages` with the accepted first-party rows
 * and sets `source` and `census_at`. Nothing else on the row changes: not
 * `watches`, not the owner's weight, silence policy, whitelist or project
 * fields. Petitions already created are never touched; the census only shapes
 * the voters of rounds opened after it.
 */
final class CensusIngest
{
    public function __construct(private readonly ConnectionInterface $db, private readonly AuditLog $audit, private readonly ?FirstParty $firstParty = null) {}

    /** The raw message JSON from `agentinbox receive`: {id, from, at, text}. */
    public function receiveGenieMessage(string $json): CensusResult
    {
        $message = json_decode($json, true);
        if (! is_array($message) || ! is_string($message['from'] ?? null) || ! is_string($message['text'] ?? null)) {
            throw new \InvalidArgumentException('Expected the raw Genie message JSON: {"id", "from", "at", "text"}.');
        }

        return $this->receive($message['from'], $message['text'], is_scalar($message['id'] ?? null) ? (string) $message['id'] : null, is_string($message['at'] ?? null) ? $message['at'] : null);
    }

    public function receive(string $from, string $text, ?string $messageId = null, ?string $at = null): CensusResult
    {
        $messageId ??= 'sha256:'.hash('sha256', $from."\n".$text);

        return $this->db->transaction(function () use ($from, $text, $messageId, $at): CensusResult {
            if (CensusReply::query()->where('genie_message_id', $messageId)->exists()) {
                return new CensusResult('duplicate', CensusReply::query()->where('genie_message_id', $messageId)->first());
            }
            if (strlen($text) > CensusParser::MAX_BYTES) {
                // Kept, but only up to the cap: an oversized message is a way to fill the ledger.
                return $this->store($messageId, $from, mb_strcut($text, 0, CensusParser::MAX_BYTES, 'UTF-8'), $at, null, 'invalid', 'too_large');
            }
            $parsed = CensusParser::parse($text, $this->firstParty);
            if (! $parsed['ok']) {
                return $this->store($messageId, $from, $text, $at, null, 'invalid', $parsed['problem']);
            }
            $census = $parsed['census'];

            $request = CensusRequest::query()->where('nonce', $census['nonce'])->lockForUpdate()->first();
            if ($request === null) {
                return $this->store($messageId, $from, $text, $at, null, 'unsolicited', 'unknown_nonce', $census);
            }
            if ($request->agent_address !== $from) {
                // The nonce was someone else's request: refused, never applied.
                return $this->store($messageId, $from, $text, $at, null, 'unsolicited', 'sender_mismatch', $census);
            }
            if ($request->answered_at !== null) {
                return $this->store($messageId, $from, $text, $at, $request, 'invalid', 'already_answered', $census);
            }
            $consumer = Consumer::query()->lockForUpdate()->find($request->consumer_id);
            if ($consumer === null || $consumer->address !== $from || $consumer->removed_at !== null) {
                return $this->store($messageId, $from, $text, $at, $request, 'invalid', 'member_removed', $census);
            }

            $result = $this->store($messageId, $from, $text, $at, $request, 'applied', null, $census);
            $now = Carbon::now();
            $before = ['source' => $consumer->source, 'census_at' => self::at($consumer->census_at), 'packages' => self::keys((array) $consumer->packages)];
            $old = self::versions((array) $consumer->packages);

            $consumer->forceFill(['packages' => $census['packages'], 'source' => 'census', 'census_at' => $now])->save();
            $request->forceFill(['answered_at' => $now, 'reply_id' => $result->reply->id])->save();

            $new = self::versions($census['packages']);
            $changed = [];
            foreach (array_intersect_key($new, $old) as $key => $version) {
                if ($old[$key] !== $version) {
                    $changed[] = ['package' => $key, 'from' => $old[$key], 'to' => $version];
                }
            }
            $dropped = [];
            foreach ($census['dropped'] as $row) {
                $dropped[$row['reason']] = ($dropped[$row['reason']] ?? 0) + 1;
            }
            ksort($dropped);

            // Only validated names, codes and counts: no free text from the reply
            // reaches the audit log, and so the feed.
            $this->audit->append(null, $from, 'census.applied', [
                'address' => $from,
                'request' => $request->id,
                'reply' => $result->reply->id,
                'before' => $before,
                'after' => ['source' => 'census', 'census_at' => self::at($now), 'packages' => self::keys($census['packages'])],
                'added' => array_values(array_keys(array_diff_key($new, $old))),
                'removed' => array_values(array_keys(array_diff_key($old, $new))),
                'changed' => $changed,
                'dropped' => $dropped,
                'apps' => count($census['apps']),
                'flags' => count($result->reply->flags),
            ]);

            return $result;
        });
    }

    private function store(string $messageId, string $from, string $text, ?string $at, ?CensusRequest $request, string $status, ?string $problem, ?array $census = null): CensusResult
    {
        $reply = CensusReply::query()->create([
            'request_id' => $request?->id,
            'genie_message_id' => $messageId,
            'from_address' => $from,
            'apps' => $census === null ? [] : array_map(fn (array $a) => [
                'name' => Sanitiser::clean($a['name']),
                'lockfiles' => array_map(Sanitiser::clean(...), $a['lockfiles']),
            ], $census['apps']),
            'packages' => $census['packages'] ?? [],
            'dropped' => $census === null ? [] : array_map(fn (array $d) => [...$d, 'name' => Sanitiser::clean($d['name'])], $census['dropped']),
            'raw_text' => $text,
            'flags' => Sanitiser::flags($text),
            'received_at' => self::when($at),
            'status' => $status,
            'problem' => $problem,
        ]);
        if ($status !== 'applied') {
            $this->audit->append(null, $from, 'census.'.$status, ['reply' => $reply->id, 'problem' => $problem, 'request' => $request?->id]);
        }

        return new CensusResult($status, $reply, $problem);
    }

    /** @return list<string> `ecosystem:name@version` (the constraint, for a hand-written row) */
    private static function keys(array $packages): array
    {
        $out = [];
        foreach (self::versions($packages) as $key => $version) {
            $out[] = $key.'@'.$version;
        }

        return $out;
    }

    /** @return array<string, string> `ecosystem:name` => version or constraint */
    private static function versions(array $packages): array
    {
        $out = [];
        foreach ($packages as $p) {
            if (is_array($p) && is_string($p['ecosystem'] ?? null) && is_string($p['name'] ?? null)) {
                $out[$p['ecosystem'].':'.$p['name']] = (string) ($p['version'] ?? $p['constraint'] ?? '*');
            }
        }

        return $out;
    }

    private static function at(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

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
