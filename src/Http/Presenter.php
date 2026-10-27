<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Models\AuditEvent;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Decision;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Models\Round;
use ChangeCouncil\Models\Vote;

/**
 * Wire shapes for the API and the lab pages. Free text from replies goes out
 * as plain JSON strings; rendering it as text (never HTML or markdown) is the
 * page's job, and the pages are source-checked for it.
 */
final class Presenter
{
    public function __construct(private readonly Ledger $ledger) {}

    public function summary(Change $change): array
    {
        $round = $change->currentRound();
        $petitions = $round === null ? collect() : Petition::query()->where('round_id', $round->id)->get();

        return [
            'ref' => $change->ref,
            'title' => $change->title,
            'state' => $change->state,
            'classes' => $change->classes,
            'packages' => $change->packages,
            'initiation_source' => $change->initiation_source,
            'initiator' => $change->initiator,
            'whitelisted' => $change->whitelisted,
            'current_round' => $change->current_round,
            'deadline' => $round?->deadline()->utc()->format('Y-m-d\TH:i:s\Z'),
            'petitions' => [
                'total' => $petitions->count(),
                'answered' => $petitions->filter(fn (Petition $p) => in_array($p->outcome, ['approve', 'conflict', 'abstain'], true))->count(),
                'conflicts' => $petitions->where('outcome', 'conflict')->count(),
                'queued' => $petitions->where('delivery', 'queued')->count(),
            ],
            'approved_unopposed' => (bool) $change->approved_unopposed,
            'approved_with_notes' => (bool) $change->approved_with_notes,
            'late_conflict' => (bool) $change->late_conflict,
            'decided_by' => $change->decided_by,
            'updated_at' => $change->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function detail(Change $change): array
    {
        $rounds = Round::query()->where('change_id', $change->id)->orderBy('number')->get()->map(fn (Round $round) => $this->round($change, $round))->all();

        return [
            'change' => [
                ...$this->summary($change),
                'summary' => $change->summary,
                'approach' => $change->approach,
                'evidence' => $change->evidence,
                'links' => $change->links,
                'pros' => $change->pros,
                'cons' => $change->cons,
                'silence_overrides' => (object) $change->silence_overrides,
                'decision_reason' => $change->decision_reason,
                'decided_at' => $change->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'rounds' => $rounds,
            'open_conflicts' => $this->ledger->openConflicts($change),
            'decisions' => Decision::query()->where('change_id', $change->id)->orderBy('id')->get()->map(fn (Decision $d) => [
                'id' => $d->id, 'outcome' => $d->outcome, 'decided_by' => $d->decided_by, 'reason' => $d->reason,
                'acknowledged' => $d->acknowledged, 'ledger_head' => $d->ledger_head, 'decided_at' => $d->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all(),
            'audit' => AuditEvent::query()->where('change_id', $change->id)->orderBy('seq')->get()->map(fn (AuditEvent $e) => [
                'seq' => $e->seq, 'at' => $e->at, 'actor' => $e->actor, 'type' => $e->type, 'data' => (object) $e->data, 'hash' => $e->hash,
            ])->all(),
            'owner_actions' => $this->ledger->ownerActions($change),
        ];
    }

    public function round(Change $change, Round $round): array
    {
        return [
            'number' => $round->number,
            'approach' => $round->approach,
            'approach_digest' => $round->approach_digest,
            'opened_at' => $round->opened_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'closes_at' => $round->closes_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'extended_until' => $round->extended_until?->utc()->format('Y-m-d\TH:i:s\Z'),
            'closed_at' => $round->closed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'outcome' => $round->outcome,
            'tally' => $round->tally,
            'petitions' => Petition::query()->where('round_id', $round->id)->orderBy('id')->get()->map(fn (Petition $p) => [
                'id' => $p->id,
                'agent_address' => $p->agent_address,
                'tier' => $p->tier,
                'why' => $p->why,
                'delivery' => $p->delivery,
                'delivery_detail' => $p->delivery_detail,
                'outcome' => $p->outcome,
                'catch_up' => $p->catch_up,
                'effective_silence_policy' => $this->ledger->policyFor($change, $p->agent_address),
                'votes' => Vote::query()->where('petition_id', $p->id)->orderBy('id')->get()->map(fn (Vote $v) => self::vote($v))->all(),
            ])->all(),
        ];
    }

    public static function vote(Vote $v): array
    {
        return [
            'id' => $v->id,
            'from_address' => $v->from_address,
            'genie_message_id' => $v->genie_message_id,
            'status' => $v->status,
            'problem' => $v->problem,
            'verdict' => $v->verdict,
            'impact' => $v->impact,
            'alternative' => $v->alternative,
            'reason' => $v->reason,
            'flags' => $v->flags,
            'raw_text' => $v->raw_text,
            'received_at' => $v->received_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public static function consumer(Consumer $c): array
    {
        return [
            'address' => $c->address,
            'packages' => $c->packages,
            'watches' => $c->watches,
            'silence_policy' => $c->silence_policy,
            'whitelisted' => $c->whitelisted,
            'internal' => $c->internal,
            'source' => $c->source,
            'census_at' => $c->census_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'notes' => $c->notes,
        ];
    }
}
