<?php

declare(strict_types=1);

namespace ChangeCouncil\Ledger;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Decision;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Models\Round;
use ChangeCouncil\Models\Vote;
use ChangeCouncil\Petitions\DeliveryResult;
use ChangeCouncil\Petitions\PetitionText;
use ChangeCouncil\Transport\PetitionTransport;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The state machine. Every transition is one DB transaction and one or more
 * audit events; nothing else writes a Change's state. See {@see States}.
 *
 * Owner methods take the owner's lab identity and record `owner:<id>`. The
 * ledger cannot tell an owner from an agent on its own, so the ONLY caller of
 * an owner method is the HTTP API behind the host's `owner` ability (the
 * authenticated lab UI). No artisan command calls one: an agent can run any
 * command with any flag. `CommandsTest` enforces that.
 */
final class Ledger
{
    public const BLOCKING = ['blocker', 'major'];

    public const SILENT_OUTCOMES = ['silent', 'unreachable', 'undelivered', 'invalid'];

    /** @param array{origin: string, lab_url: string, round1_hours: int, round2_hours: int, extension_hours: int, census_max_age_days: int} $settings */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLog $audit,
        private readonly PetitionTransport $transport,
        private readonly Whitelist $whitelist,
        private readonly array $settings,
    ) {}

    public function find(string $ref): ?Change
    {
        return Change::query()->where('ref', $ref)->first();
    }

    // ------------------------------------------------------------------ initiation

    /**
     * @param array{title: string, summary: string, approach: string, classes: list<string>, packages: list<array>, evidence?: array, links?: array, source: string, initiator: string, pros?: ?string, cons?: ?string} $input
     */
    public function propose(array $input): Change
    {
        foreach (['title', 'summary', 'approach', 'initiator', 'source'] as $key) {
            if (! is_string($input[$key] ?? null) || trim($input[$key]) === '') {
                throw new Refused('invalid_request', "{$key} is required");
            }
        }
        if (! in_array($input['source'], States::SOURCES, true)) {
            throw new Refused('invalid_source', 'source must be one of '.implode(', ', States::SOURCES));
        }
        $classes = array_values(array_filter((array) ($input['classes'] ?? []), 'is_string'));
        $packages = $this->packages($input['packages'] ?? null);

        // Computed here, never taken from the input. Owner ruling q_5e39ab76:
        // whitelisted contributors (agents, or GitHub authors for an issue)
        // initiate straight into round 1; everything non-whitelisted goes to
        // the owner first.
        $whitelisted = $this->whitelist->initiator($input['source'], $input['initiator']);
        $hitl = ! $whitelisted;
        $pros = trim((string) ($input['pros'] ?? ''));
        $cons = trim((string) ($input['cons'] ?? ''));
        if ($hitl && ($pros === '' || $cons === '')) {
            throw new Refused('pros_cons_required', 'an initiation that goes to the owner must carry the initiating agent\'s pros/cons summary');
        }

        return $this->db->transaction(function () use ($input, $classes, $packages, $whitelisted, $hitl, $pros, $cons): Change {
            $origin = $this->settings['origin'];
            $n = Change::query()->where('origin', $origin)->count() + 1;
            $change = Change::query()->create([
                'ref' => sprintf('CC-%s-%04d', $origin, $n),
                'origin' => $origin,
                'title' => trim($input['title']),
                'summary' => trim($input['summary']),
                'approach' => $input['approach'],
                'classes' => $classes,
                'packages' => $packages,
                'evidence' => is_array($input['evidence'] ?? null) ? $input['evidence'] : [],
                'links' => is_array($input['links'] ?? null) ? $input['links'] : [],
                'initiation_source' => $input['source'],
                'initiator' => $input['initiator'],
                'whitelisted' => $whitelisted,
                'pros' => $pros === '' ? null : $pros,
                'cons' => $cons === '' ? null : $cons,
                'state' => States::AWAITING_OWNER,
                'silence_overrides' => [],
            ]);
            $this->audit->append($change->id, $input['initiator'], 'change.proposed', [
                'ref' => $change->ref, 'source' => $input['source'], 'whitelisted' => $whitelisted, 'hitl' => $hitl,
                'classes' => $classes, 'packages' => array_map(fn ($p) => $p['ecosystem'].':'.$p['name'], $packages),
            ]);
            if (! $hitl) {
                $this->openRound($change, 1, $change->approach, $input['initiator']);
            }

            return $change->fresh();
        });
    }

    public function approveInitiation(Change $change, string $owner, ?string $note = null): Change
    {
        return $this->db->transaction(function () use ($change, $owner, $note): Change {
            $change = $this->lock($change);
            $this->expect($change, [States::AWAITING_OWNER]);
            $this->audit->append($change->id, self::owner($owner), 'initiation.approved', ['note' => $note]);
            $this->openRound($change, 1, $change->approach, self::owner($owner));

            return $change->fresh();
        });
    }

    public function rejectInitiation(Change $change, string $owner, string $reason): Change
    {
        $this->requireReason($reason);

        return $this->db->transaction(function () use ($change, $owner, $reason): Change {
            $change = $this->lock($change);
            $this->expect($change, [States::AWAITING_OWNER]);
            $this->audit->append($change->id, self::owner($owner), 'initiation.rejected', ['reason' => $reason]);
            $this->decide($change, States::REJECTED, self::owner($owner), $reason);

            return $change->fresh();
        });
    }

    // ------------------------------------------------------------------ rounds

    private function openRound(Change $change, int $number, string $approach, string $actor): Round
    {
        $now = Carbon::now();
        $hours = $number === 1 ? $this->settings['round1_hours'] : $this->settings['round2_hours'];
        $round = Round::query()->create([
            'change_id' => $change->id,
            'number' => $number,
            'approach' => $approach,
            'approach_digest' => 'sha256:'.hash('sha256', $approach),
            'opened_at' => $now,
            'closes_at' => $now->copy()->addHours($hours),
        ]);
        $change->forceFill([
            'state' => $number === 1 ? States::ROUND1_OPEN : States::ROUND2_OPEN,
            'current_round' => $number,
            'approach' => $approach,
        ])->save();
        $this->audit->append($change->id, $actor, 'round.opened', ['round' => $number, 'digest' => $round->approach_digest, 'closes_at' => $round->closes_at->utc()->format('Y-m-d\TH:i:s\Z')]);

        foreach ($this->voters($change) as [$consumer, $tier, $why]) {
            $petition = Petition::query()->create([
                'round_id' => $round->id,
                'change_id' => $change->id,
                'consumer_id' => $consumer->id,
                'agent_address' => $consumer->address,
                'tier' => $tier,
                'why' => $why,
                'nonce' => bin2hex(random_bytes(8)),
                'queued_at' => $now,
                'delivery' => 'queued',
            ]);
            $this->audit->append($change->id, $actor, 'petition.queued', ['petition' => $petition->id, 'to' => $consumer->address, 'tier' => $tier, 'round' => $number]);
            $this->transport->queue($petition, PetitionText::render($change, $round, $petition, $this->settings['lab_url']));
        }

        return $round;
    }

    /** @return list<array{0: Consumer, 1: string, 2: string}> required + informed, never internal */
    private function voters(Change $change): array
    {
        $out = [];
        foreach (Consumer::query()->where('internal', false)->orderBy('address')->get() as $consumer) {
            $required = null;
            $watched = null;
            foreach ($change->packages as $p) {
                $declared = $consumer->declares($p['ecosystem'], $p['name']);
                if ($declared !== null && $required === null) {
                    $census = $consumer->census_at === null ? 'no census yet, '.$consumer->source : 'census '.$consumer->census_at->format('Y-m-d');
                    $required = "you declared {$p['name']} ".($declared['constraint'] ?? '*')." ({$census})";
                }
                if ($consumer->watchesPackage($p['ecosystem'], $p['name']) && $watched === null) {
                    $watched = "you watch {$p['name']}";
                }
            }
            if ($required !== null) {
                $out[] = [$consumer, 'required', $required];
            } elseif ($watched !== null) {
                $out[] = [$consumer, 'informed', $watched];
            }
        }

        return $out;
    }

    /** The initiator answers blocking conflicts with a NEW approach; that is round 2. */
    public function revise(Change $change, string $approach, string $by): Change
    {
        return $this->db->transaction(function () use ($change, $approach, $by): Change {
            $change = $this->lock($change);
            $this->expect($change, [States::AWAITING_REVISION]);
            if ($by !== $change->initiator) {
                throw new Refused('not_initiator', 'only the initiator revises the approach');
            }
            if (trim($approach) === '' || hash('sha256', $approach) === hash('sha256', (string) $change->currentRound()?->approach)) {
                throw new Refused('approach_unchanged', 'round 2 needs a revised approach');
            }
            $this->audit->append($change->id, $by, 'approach.revised', ['round' => 2, 'digest' => 'sha256:'.hash('sha256', $approach)]);
            $this->openRound($change, 2, $approach, $by);

            return $change->fresh();
        });
    }

    // ------------------------------------------------------------------ delivery

    /** Records the clerk's raw `agentinbox send` result for a petition. */
    public function markSent(Petition $petition, string $rawResult): Petition
    {
        $result = DeliveryResult::parse($rawResult);

        return $this->db->transaction(function () use ($petition, $result): Petition {
            $petition = Petition::query()->lockForUpdate()->findOrFail($petition->id);
            $petition->forceFill([
                'delivery' => $result->delivery,
                'delivery_detail' => $result->detail,
                'sent_at' => $result->delivery === 'sent' ? Carbon::now() : null,
            ])->save();
            $this->audit->append($petition->change_id, 'clerk', 'petition.'.$result->delivery, ['petition' => $petition->id, 'to' => $petition->agent_address]);

            return $petition;
        });
    }

    // ------------------------------------------------------------------ tally

    /**
     * Closes every open round that is due: its deadline (or extension) has
     * passed, or every petition has a counted vote. Silent required voters get
     * ONE extension first.
     *
     * @return list<array{ref: string, action: string, state: string}>
     */
    public function tally(): array
    {
        $out = [];
        foreach (Change::query()->whereIn('state', States::OPEN_ROUND)->orderBy('ref')->get() as $change) {
            $action = $this->tallyChange($change);
            $out[] = ['ref' => $change->ref, 'action' => $action, 'state' => $change->fresh()->state];
        }

        return $out;
    }

    public function tallyChange(Change $change): string
    {
        return $this->db->transaction(function () use ($change): string {
            $change = $this->lock($change);
            if (! in_array($change->state, States::OPEN_ROUND, true)) {
                return 'not_open';
            }
            $round = $change->currentRound();
            $petitions = Petition::query()->where('round_id', $round->id)->get();
            $votes = [];
            foreach ($petitions as $p) {
                $votes[$p->id] = $p->countedVote();
            }
            $required = $petitions->where('tier', 'required');
            $now = Carbon::now();
            $due = $now->greaterThanOrEqualTo($round->deadline());
            $allAnswered = $petitions->every(fn (Petition $p) => $votes[$p->id] !== null);
            $requiredAnswered = $required->every(fn (Petition $p) => $votes[$p->id] !== null);

            if (! $due && ! $allAnswered && ! ($required->isNotEmpty() && $requiredAnswered)) {
                return 'waiting';
            }
            if ($due && ! $requiredAnswered && $round->extended_until === null) {
                $round->forceFill(['extended_until' => $round->closes_at->copy()->addHours($this->settings['extension_hours'])])->save();
                $this->audit->append($change->id, 'tally', 'round.extended', [
                    'round' => $round->number,
                    'until' => $round->extended_until->utc()->format('Y-m-d\TH:i:s\Z'),
                    'silent' => $required->filter(fn (Petition $p) => $votes[$p->id] === null)->pluck('agent_address')->values()->all(),
                ]);

                return 'extended';
            }

            return $this->close($change, $round, $petitions, $votes);
        });
    }

    /** @param \Illuminate\Support\Collection<int, Petition> $petitions */
    private function close(Change $change, Round $round, $petitions, array $votes): string
    {
        $counts = array_fill_keys(['approve', 'conflict', 'abstain', 'silent', 'unreachable', 'undelivered', 'invalid'], 0);
        $blocking = [];
        $notes = [];
        $escalate = [];
        $catchUp = [];
        foreach ($petitions as $p) {
            $vote = $votes[$p->id];
            $outcome = $vote?->verdict ?? match (true) {
                in_array($p->delivery, ['unreachable', 'undelivered'], true) => $p->delivery,
                Vote::query()->where('petition_id', $p->id)->where('status', 'invalid')->exists() => 'invalid',
                default => 'silent',
            };
            $counts[$outcome]++;
            if ($vote !== null && $vote->verdict === 'conflict') {
                if (in_array($vote->worstSeverity(), self::BLOCKING, true)) {
                    $blocking[] = $vote->id;
                } else {
                    $notes[] = $vote->id;
                }
            }
            $fill = ['outcome' => $outcome];
            if ($p->tier === 'required' && in_array($outcome, self::SILENT_OUTCOMES, true)) {
                if ($this->policyFor($change, $p->agent_address) === 'proceed_and_notify') {
                    $catchUp[] = $p->agent_address;
                    $fill['catch_up'] = true;
                    $this->audit->append($change->id, 'tally', 'petition.catch_up', ['petition' => $p->id, 'to' => $p->agent_address, 'outcome' => $outcome]);
                } else {
                    $escalate[] = $p->agent_address;
                }
            }
            $p->forceFill($fill)->save();
        }

        $censusStale = false;
        if ($petitions->where('tier', 'required')->isEmpty()) {
            $maxAge = Carbon::now()->subDays($this->settings['census_max_age_days']);
            $censusStale = Consumer::query()->where('internal', false)
                ->where(fn ($q) => $q->whereNull('census_at')->orWhere('census_at', '<', $maxAge))->exists();
        }

        [$state, $reason] = match (true) {
            $blocking !== [] && $round->number === 1 => [States::AWAITING_REVISION, 'blocking_conflict'],
            $blocking !== [] => [States::ESCALATED, 'conflict_after_revision'],
            $escalate !== [] => [States::ESCALATED, 'silence'],
            $censusStale => [States::ESCALATED, 'census_stale'],
            default => [States::APPROVED, $petitions->where('tier', 'required')->isEmpty() ? 'unopposed' : 'tally'],
        };

        $tally = ['counts' => $counts, 'blocking' => $blocking, 'notes' => $notes, 'escalate' => $escalate, 'catch_up' => $catchUp, 'reason' => $reason];
        $round->forceFill(['closed_at' => Carbon::now(), 'tally' => $tally, 'outcome' => $state])->save();
        $this->audit->append($change->id, 'tally', 'round.closed', ['round' => $round->number, 'outcome' => $state, 'reason' => $reason, 'counts' => $counts]);

        if ($state === States::APPROVED) {
            $change->forceFill([
                'approved_unopposed' => $reason === 'unopposed',
                'approved_with_notes' => $notes !== [],
            ])->save();
            $this->decide($change, States::APPROVED, 'tally', null);
        } else {
            $change->forceFill(['state' => $state])->save();
            $this->audit->append($change->id, 'tally', $state === States::AWAITING_REVISION ? 'change.revision_required' : 'change.escalated', ['reason' => $reason]);
        }

        return 'closed';
    }

    // ------------------------------------------------------------------ owner

    /**
     * The owner decides at any point. A reason is required; approving over
     * open blocking conflicts requires acknowledging every one of them.
     *
     * @param list<int> $acknowledge vote ids
     */
    public function override(Change $change, string $owner, string $decision, string $reason, array $acknowledge = []): Change
    {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new Refused('invalid_request', 'decision must be approve or reject');
        }
        $this->requireReason($reason);

        return $this->db->transaction(function () use ($change, $owner, $decision, $reason, $acknowledge): Change {
            $change = $this->lock($change);
            $target = $decision === 'approve' ? States::APPROVED : States::REJECTED;
            if (in_array($change->state, [States::WITHDRAWN, States::REVOKED, $target], true)) {
                throw new Refused('invalid_state', "{$change->ref} is {$change->state}");
            }
            $conflicts = $this->openConflicts($change);
            $acknowledge = array_values(array_unique(array_map('intval', $acknowledge)));
            if ($decision === 'approve') {
                $missing = array_values(array_diff($conflicts, $acknowledge));
                if ($missing !== []) {
                    throw new Refused('acknowledge_conflicts', 'acknowledge each open conflict before approving over it', ['missing' => $missing]);
                }
            }
            $round = $change->currentRound();
            if ($round !== null && $round->closed_at === null) {
                $round->forceFill(['closed_at' => Carbon::now(), 'outcome' => 'owner_override'])->save();
            }
            $this->audit->append($change->id, self::owner($owner), 'owner.override', [
                'decision' => $decision, 'reason' => $reason, 'from_state' => $change->state, 'acknowledged' => $acknowledge,
            ]);
            $this->decide($change, $target, self::owner($owner), $reason, $decision === 'approve' ? $acknowledge : []);

            return $change->fresh();
        });
    }

    public function revoke(Change $change, string $owner, string $reason): Change
    {
        $this->requireReason($reason);

        return $this->db->transaction(function () use ($change, $owner, $reason): Change {
            $change = $this->lock($change);
            $this->expect($change, [States::APPROVED]);
            $this->decide($change, States::REVOKED, self::owner($owner), $reason);

            return $change->fresh();
        });
    }

    public function withdraw(Change $change, string $by): Change
    {
        return $this->db->transaction(function () use ($change, $by): Change {
            $change = $this->lock($change);
            if (States::isFinal($change->state)) {
                throw new Refused('invalid_state', "{$change->ref} is {$change->state}");
            }
            if ($by !== $change->initiator) {
                throw new Refused('not_initiator', 'only the initiator withdraws a Change');
            }
            $round = $change->currentRound();
            if ($round !== null && $round->closed_at === null) {
                $round->forceFill(['closed_at' => Carbon::now(), 'outcome' => 'withdrawn'])->save();
            }
            $change->forceFill(['state' => States::WITHDRAWN])->save();
            $this->audit->append($change->id, $by, 'change.withdrawn', []);

            return $change->fresh();
        });
    }

    public function setSilencePolicy(string $address, string $policy, string $owner): Consumer
    {
        if (! in_array($policy, Consumer::POLICIES, true)) {
            throw new Refused('invalid_policy', 'policy must be one of '.implode(', ', Consumer::POLICIES));
        }

        return $this->db->transaction(function () use ($address, $policy, $owner): Consumer {
            $consumer = Consumer::query()->where('address', $address)->first() ?? throw new Refused('not_found', "no consumer {$address}");
            $before = $consumer->silence_policy;
            $consumer->forceFill(['silence_policy' => $policy])->save();
            $this->audit->append(null, self::owner($owner), 'consumer.silence_policy', ['address' => $address, 'from' => $before, 'to' => $policy]);

            return $consumer;
        });
    }

    /** A per-Change override of one consumer's policy; null removes it. */
    public function setChangeSilencePolicy(Change $change, string $address, ?string $policy, string $owner): Change
    {
        if ($policy !== null && ! in_array($policy, Consumer::POLICIES, true)) {
            throw new Refused('invalid_policy', 'policy must be one of '.implode(', ', Consumer::POLICIES).' or null');
        }

        return $this->db->transaction(function () use ($change, $address, $policy, $owner): Change {
            $change = $this->lock($change);
            if (! Consumer::query()->where('address', $address)->exists()) {
                throw new Refused('not_found', "no consumer {$address}");
            }
            $overrides = $change->silence_overrides;
            if ($policy === null) {
                unset($overrides[$address]);
            } else {
                $overrides[$address] = $policy;
            }
            $change->forceFill(['silence_overrides' => $overrides])->save();
            $this->audit->append($change->id, self::owner($owner), 'change.silence_policy', ['address' => $address, 'policy' => $policy]);

            return $change->fresh();
        });
    }

    // ------------------------------------------------------------------ ingest hooks

    /** Called by Ingest for a late vote from a consumer on catch-up. */
    public function recordLate(Change $change, Petition $petition, Vote $vote): void
    {
        $this->audit->append($change->id, $petition->agent_address, 'vote.late', ['petition' => $petition->id, 'vote' => $vote->id, 'verdict' => $vote->verdict]);
        if ($vote->verdict === 'conflict') {
            $change->forceFill(['late_conflict' => true])->save();
            $this->audit->append($change->id, 'ledger', 'vote.late_conflict', ['petition' => $petition->id, 'vote' => $vote->id, 'severity' => $vote->worstSeverity()]);
        }
    }

    public function audit(): AuditLog
    {
        return $this->audit;
    }

    public function policyFor(Change $change, string $address): string
    {
        $override = $change->silence_overrides[$address] ?? null;
        if (is_string($override) && in_array($override, Consumer::POLICIES, true)) {
            return $override;
        }

        return Consumer::query()->where('address', $address)->value('silence_policy') ?? 'escalate';
    }

    /** Owner actions that make sense in the Change's current state, for the UI. */
    public function ownerActions(Change $change): array
    {
        $actions = [];
        if ($change->state === States::AWAITING_OWNER) {
            $actions[] = 'approve_initiation';
            $actions[] = 'reject_initiation';
        }
        if (! in_array($change->state, [States::WITHDRAWN, States::REVOKED], true)) {
            $actions[] = 'override';
        }
        if ($change->state === States::APPROVED) {
            $actions[] = 'revoke';
        }
        $actions[] = 'silence_policy';

        return $actions;
    }

    /** @return list<int> counted blocking conflict vote ids in the current round */
    public function openConflicts(Change $change): array
    {
        $round = $change->currentRound();
        if ($round === null) {
            return [];
        }
        $ids = [];
        foreach (Petition::query()->where('round_id', $round->id)->get() as $p) {
            $vote = $p->countedVote();
            if ($vote !== null && $vote->verdict === 'conflict' && in_array($vote->worstSeverity(), self::BLOCKING, true)) {
                $ids[] = $vote->id;
            }
        }
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------ helpers

    private function decide(Change $change, string $state, string $by, ?string $reason, array $acknowledged = []): void
    {
        $now = Carbon::now();
        $change->forceFill(['state' => $state, 'decided_by' => $by, 'decision_reason' => $reason, 'decided_at' => $now])->save();
        $event = $this->audit->append($change->id, $by, 'change.'.$state, ['reason' => $reason]);
        Decision::query()->create([
            'change_id' => $change->id,
            'outcome' => $state,
            'decided_by' => $by,
            'reason' => $reason,
            'acknowledged' => $acknowledged,
            'ledger_head' => $event->hash,
            'decided_at' => $now,
        ]);
    }

    private function lock(Change $change): Change
    {
        return Change::query()->lockForUpdate()->findOrFail($change->id);
    }

    /** @param list<string> $states */
    private function expect(Change $change, array $states): void
    {
        if (! in_array($change->state, $states, true)) {
            throw new Refused('invalid_state', "{$change->ref} is {$change->state}, expected ".implode(' or ', $states));
        }
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new Refused('reason_required', 'an owner decision needs a reason');
        }
    }

    private static function owner(string $id): string
    {
        return str_starts_with($id, 'owner:') ? $id : 'owner:'.$id;
    }

    /** @return list<array{ecosystem: string, name: string, from?: string, to?: string}> */
    private function packages(mixed $packages): array
    {
        if (! is_array($packages) || $packages === [] || ! array_is_list($packages)) {
            throw new Refused('invalid_request', 'packages must be a non-empty list of {ecosystem, name}');
        }
        $out = [];
        foreach ($packages as $p) {
            if (! is_array($p) || ! is_string($p['ecosystem'] ?? null) || ! is_string($p['name'] ?? null) || $p['name'] === '') {
                throw new Refused('invalid_request', 'each package needs ecosystem and name');
            }
            $row = ['ecosystem' => $p['ecosystem'], 'name' => $p['name']];
            foreach (['from', 'to'] as $k) {
                if (isset($p[$k]) && is_string($p[$k])) {
                    $row[$k] = $p[$k];
                }
            }
            $out[] = $row;
        }

        return $out;
    }
}
