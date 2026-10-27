<?php

declare(strict_types=1);

namespace ChangeCouncil\Audit;

use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Support\CanonicalJson;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The append-only, hash-chained audit log.
 *
 *   hash = sha256(prev_hash . "\n" . canonical({seq, change_id, at, actor, type, data}))
 *
 * The genesis prev_hash is 64 zeros. verify() recomputes every row and checks
 * every link and that seq has no gap, so an edit, a deletion or a re-hashed row
 * made outside the app is found at the row where it happened (or the next one).
 * The head goes into every decision record.
 */
final class AuditLog
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @param array<string, mixed> $data */
    public function append(?string $changeId, string $actor, string $type, array $data = []): AuditEvent
    {
        return $this->db->transaction(function () use ($changeId, $actor, $type, $data): AuditEvent {
            $last = AuditEvent::query()->orderByDesc('seq')->first();
            $seq = $last === null ? 1 : $last->seq + 1;
            $prev = $last === null ? self::GENESIS : $last->hash;
            $at = Carbon::now()->utc()->format('Y-m-d\TH:i:s.u\Z');

            return AuditEvent::query()->create([
                'seq' => $seq,
                'change_id' => $changeId,
                'at' => $at,
                'actor' => $actor,
                'type' => $type,
                'data' => $data,
                'prev_hash' => $prev,
                'hash' => self::hashOf($prev, $seq, $changeId, $at, $actor, $type, $data),
            ]);
        });
    }

    public static function hashOf(string $prev, int $seq, ?string $changeId, string $at, string $actor, string $type, array $data): string
    {
        return hash('sha256', $prev."\n".CanonicalJson::encode([
            'seq' => $seq,
            'change_id' => $changeId,
            'at' => $at,
            'actor' => $actor,
            'type' => $type,
            'data' => $data === [] ? new \stdClass : $data,
        ]));
    }

    public function head(): string
    {
        return (string) (AuditEvent::query()->orderByDesc('seq')->value('hash') ?? self::GENESIS);
    }

    /**
     * Reads raw rows (not models) so a value the model would reject still
     * reaches the check.
     *
     * @return array{ok: bool, checked: int, head: string, seq?: int, problem?: string}
     */
    public function verify(): array
    {
        $prev = self::GENESIS;
        $expected = 1;
        $checked = 0;
        foreach ($this->db->table('council_events')->orderBy('seq')->cursor() as $row) {
            $seq = (int) $row->seq;
            if ($seq !== $expected) {
                return ['ok' => false, 'checked' => $checked, 'head' => $prev, 'seq' => $seq, 'problem' => 'sequence_gap'];
            }
            if ($row->prev_hash !== $prev) {
                return ['ok' => false, 'checked' => $checked, 'head' => $prev, 'seq' => $seq, 'problem' => 'broken_link'];
            }
            $data = json_decode((string) $row->data, true);
            if (! is_array($data) || self::hashOf($prev, $seq, $row->change_id, (string) $row->at, (string) $row->actor, (string) $row->type, $data) !== $row->hash) {
                return ['ok' => false, 'checked' => $checked, 'head' => $prev, 'seq' => $seq, 'problem' => 'hash_mismatch'];
            }
            $prev = $row->hash;
            $expected++;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'head' => $prev];
    }
}
