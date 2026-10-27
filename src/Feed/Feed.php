<?php

declare(strict_types=1);

namespace ChangeCouncil\Feed;

use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Change;
use stdClass;

/**
 * The Overwatch pull feed, schema change-council/feed.v1
 * (resources/schema/feed.v1.json; docs/feed.md). Generic and read-only: audit
 * events after a cursor, plus a snapshot of every Change those events touched.
 * It never carries reply free text; the lab is where replies are read.
 *
 * Versioning follows charlotte's events.v1: adding an event type, an optional
 * field or a state is additive and stays v1; removing or renaming anything is
 * feed.v2, served beside v1.
 */
final class Feed
{
    public const SCHEMA = 'change-council/feed.v1';

    /** Audit `data` keys that may carry text a person or agent wrote. */
    private const TEXT_KEYS = ['note', 'reason'];

    public function __construct(private readonly string $origin) {}

    public function page(int $since, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        $events = AuditEvent::query()->where('seq', '>', $since)->orderBy('seq')->limit($limit)->get();
        $refs = Change::query()->whereIn('id', $events->pluck('change_id')->filter()->unique()->values())->pluck('ref', 'id');

        $out = [];
        foreach ($events as $event) {
            $data = $event->data;
            foreach (self::TEXT_KEYS as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = $data[$key] === null ? null : '[text in the lab]';
                }
            }
            $out[] = [
                'seq' => $event->seq,
                'at' => $event->at,
                'change' => $event->change_id === null ? null : ($refs[$event->change_id] ?? null),
                'actor' => $event->actor,
                'type' => $event->type,
                'data' => $data === [] ? new stdClass : $data,
                'hash' => $event->hash,
            ];
        }

        $changes = Change::query()->whereIn('id', $refs->keys())->orderBy('ref')->get()->map(fn (Change $c) => self::snapshot($c))->values()->all();

        return [
            'schema' => self::SCHEMA,
            'origin' => $this->origin,
            'cursor' => $out === [] ? $since : end($out)['seq'],
            'events' => $out,
            'changes' => $changes,
        ];
    }

    public static function snapshot(Change $change): array
    {
        return [
            'ref' => $change->ref,
            'origin' => $change->origin,
            'title' => $change->title,
            'state' => $change->state,
            'current_round' => $change->current_round,
            'classes' => $change->classes,
            'packages' => $change->packages,
            'initiation_source' => $change->initiation_source,
            'whitelisted' => $change->whitelisted,
            'approved_unopposed' => (bool) $change->approved_unopposed,
            'approved_with_notes' => (bool) $change->approved_with_notes,
            'late_conflict' => (bool) $change->late_conflict,
            'decided_by' => $change->decided_by,
            'decided_at' => $change->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $change->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
