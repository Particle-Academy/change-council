<?php

declare(strict_types=1);

namespace ChangeCouncil\Ledger;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Decision;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Models\Round;
use ChangeCouncil\Models\Setting;
use ChangeCouncil\Models\Vote;
use ChangeCouncil\Notify\NullNotifier;
use ChangeCouncil\Notify\OwnerEvent;
use ChangeCouncil\Notify\OwnerEventNotifier;
use ChangeCouncil\Petitions\DeliveryResult;
use ChangeCouncil\Petitions\PetitionText;
use ChangeCouncil\StepUp\OwnerAction;
use ChangeCouncil\Transport\PetitionTransport;
use Closure;
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
        private readonly OwnerEventNotifier $notifier = new NullNotifier,
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
            } else {
                // The only way into awaiting_owner (see States): tell the
                // clerk, so a proposal never waits on an owner nobody told.
                $this->notifyAfterCommit($change, OwnerEvent::AWAITING_OWNER, null, null, null,
                    ['packages' => count($packages), 'classes' => count($classes)],
                    $input['source'], OwnerEvent::identity($input['initiator']));
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
            $this->notifyAfterCommit($change, OwnerEvent::INITIATION_APPROVED, States::AWAITING_OWNER);

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
            $this->notifyAfterCommit($change, OwnerEvent::INITIATION_REJECTED, States::AWAITING_OWNER);

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

    /** @return list<array{0: Consumer, 1: string, 2: string}> required + informed, never internal, never removed */
    private function voters(Change $change): array
    {
        $out = [];
        foreach (Consumer::query()->where('internal', false)->whereNull('removed_at')->orderBy('address')->get() as $consumer) {
            $required = null;
            $watched = null;
            foreach ($change->packages as $p) {
                $declared = $consumer->declares($p['ecosystem'], $p['name']);
                if ($declared !== null && $required === null) {
                    // A census row carries the installed version; a hand-written one a constraint.
                    $required = $consumer->source === 'census' && $consumer->census_at !== null
                        ? "your census lists {$p['name']} ".($declared['version'] ?? '*').' (census '.$consumer->census_at->format('Y-m-d').')'
                        : "you declared {$p['name']} ".($declared['constraint'] ?? $declared['version'] ?? '*').' ('.($consumer->census_at === null ? 'no census yet, '.$consumer->source : 'census '.$consumer->census_at->format('Y-m-d')).')';
                }
                if ($consumer->watchesPackage($p['ecosystem'], $p['name']) && $watched === null) {
                    $watched = "you watch {$p['name']}";
                }
            }
            if ($required !== null) {
                // `why` is a 255-character column; a census name and version may be long.
                $out[] = [$consumer, 'required', mb_substr($required, 0, 255)];
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

    /**
     * A queued petition that can no longer be sent (its round closed, its
     * Change was decided or withdrawn, its member was removed) is recorded as
     * `superseded` and audited as `petition.superseded`. Never deleted. One
     * that was answered is left as it is (see Petition::scopeStaleQueued).
     */
    private function supersede(Petition $petition, string $actor, string $because): void
    {
        // A reply proves it arrived, whatever the clerk recorded: not superseded.
        if ($petition->delivery !== 'queued' || Vote::query()->where('petition_id', $petition->id)->exists()) {
            return;
        }
        $petition->forceFill(['delivery' => 'superseded'])->save();
        $this->audit->append($petition->change_id, $actor, 'petition.superseded', [
            'petition' => $petition->id, 'to' => $petition->agent_address, 'round' => (int) Round::query()->whereKey($petition->round_id)->value('number'), 'because' => $because,
        ]);
    }

    private function supersedeRound(Round $round, string $actor, string $because): void
    {
        // Called once the round is closed, so every queued petition in it is stale.
        foreach (Petition::query()->where('round_id', $round->id)->staleQueued()->orderBy('id')->get() as $petition) {
            $this->supersede($petition, $actor, $because);
        }
    }

    /**
     * Housekeeping for rows an older release left `queued` after the round
     * closed: every queued petition outside Petition::sendable() becomes
     * `superseded`, audited. Idempotent; not an owner action (it decides
     * nothing, it records what already happened).
     *
     * @return list<int> the petition ids superseded
     */
    public function supersedeStale(): array
    {
        return $this->db->transaction(function (): array {
            $ids = [];
            foreach (Petition::query()->staleQueued()->lockForUpdate()->orderBy('id')->get() as $petition) {
                $this->supersede($petition, 'ledger', 'not_sendable');
                $ids[] = $petition->id;
            }

            return $ids;
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
            // A withdrawn petition (its member was removed) is neither a vote
            // nor a silence: the round neither waits for it nor counts it.
            $petitions = Petition::query()->where('round_id', $round->id)->whereNull('withdrawn_at')->get();
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
        $notes = [];
        $escalate = [];
        $catchUp = [];
        // The weighted threshold: blocking-severity conflicts block only when
        // the weight behind them reaches it. Those that do not, and a weight-0
        // member's, become notes the owner is shown. Weights are read NOW, at
        // close, and recorded in the tally, so a later change of weight never
        // re-decides a closed round.
        $weighing = $this->weigh($petitions, $votes);
        $blocking = $weighing['blocking'];
        foreach ($petitions as $p) {
            $vote = $votes[$p->id];
            $outcome = $vote?->verdict ?? match (true) {
                in_array($p->delivery, ['unreachable', 'undelivered'], true) => $p->delivery,
                Vote::query()->where('petition_id', $p->id)->where('status', 'invalid')->exists() => 'invalid',
                default => 'silent',
            };
            $counts[$outcome]++;
            if ($vote !== null && $vote->verdict === 'conflict' && ! in_array($vote->worstSeverity(), self::BLOCKING, true)) {
                $notes[] = $vote->id;
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
        $unweighted = [...$weighing['below_threshold'], ...$weighing['advisory']];
        $notes = [...$notes, ...$unweighted];
        sort($notes);

        $censusStale = false;
        if ($petitions->where('tier', 'required')->isEmpty()) {
            $maxAge = Carbon::now()->subDays($this->settings['census_max_age_days']);
            $censusStale = Consumer::query()->where('internal', false)->whereNull('removed_at')
                ->where(fn ($q) => $q->whereNull('census_at')->orWhere('census_at', '<', $maxAge))->exists();
        }

        [$state, $reason] = match (true) {
            $blocking !== [] && $round->number === 1 => [States::AWAITING_REVISION, 'blocking_conflict'],
            $blocking !== [] => [States::ESCALATED, 'conflict_after_revision'],
            $escalate !== [] => [States::ESCALATED, 'silence'],
            $censusStale => [States::ESCALATED, 'census_stale'],
            default => [States::APPROVED, $petitions->where('tier', 'required')->isEmpty() ? 'unopposed' : 'tally'],
        };

        $tally = [
            'counts' => $counts, 'blocking' => $blocking, 'notes' => $notes, 'escalate' => $escalate, 'catch_up' => $catchUp, 'reason' => $reason,
            'blocking_weight' => $weighing['blocking_weight'], 'threshold' => $weighing['threshold'], 'weights' => (object) $weighing['weights'],
            'below_threshold' => $weighing['below_threshold'], 'advisory' => $weighing['advisory'],
        ];
        $round->forceFill(['closed_at' => Carbon::now(), 'tally' => $tally, 'outcome' => $state])->save();
        $this->audit->append($change->id, 'tally', 'round.closed', [
            'round' => $round->number, 'outcome' => $state, 'reason' => $reason, 'counts' => $counts,
            'blocking_weight' => $weighing['blocking_weight'], 'threshold' => $weighing['threshold'],
        ]);
        $this->supersedeRound($round, 'tally', 'round_closed');
        if ($unweighted !== [] && ! $change->blocking_below_threshold) {
            $change->forceFill(['blocking_below_threshold' => true])->save();
        }
        $from = $change->state;

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
        $this->notifyAfterCommit($change, OwnerEvent::roundClosed($round->number), $from, $round->number, $reason, $counts);
        if ($state === States::ESCALATED) {
            $this->notifyAfterCommit($change, OwnerEvent::ESCALATED, $from, $round->number, $reason, $counts);
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
            $from = $change->state;
            $this->audit->append($change->id, self::owner($owner), 'owner.override', [
                'decision' => $decision, 'reason' => $reason, 'from_state' => $from, 'acknowledged' => $acknowledge,
            ]);
            if ($round !== null) {
                $this->supersedeRound($round, self::owner($owner), 'change_decided');
            }
            $this->decide($change, $target, self::owner($owner), $reason, $decision === 'approve' ? $acknowledge : []);
            $this->notifyAfterCommit($change, $decision === 'approve' ? OwnerEvent::OVERRIDE_APPROVE : OwnerEvent::OVERRIDE_REJECT, $from);

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
            $this->notifyAfterCommit($change, OwnerEvent::REVOKED, States::APPROVED);

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
            if ($round !== null) {
                $this->supersedeRound($round, $by, 'change_withdrawn');
            }

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

    /**
     * A member's vote weight: an integer from 0 to `council.weights.max`. 0 is
     * advisory. Takes effect at the next round close; closed rounds keep the
     * weights their tally recorded.
     */
    public function setWeight(string $address, mixed $weight, string $owner): Consumer
    {
        $max = (int) ($this->settings['max_weight'] ?? 1000);
        if (! is_int($weight) || $weight < 0 || $weight > $max) {
            throw new Refused('invalid_weight', "weight must be an integer from 0 to {$max}");
        }

        return $this->db->transaction(function () use ($address, $weight, $owner): Consumer {
            $consumer = Consumer::query()->where('address', $address)->lockForUpdate()->first() ?? throw new Refused('not_found', "no consumer {$address}");
            $before = (int) $consumer->weight;
            $consumer->forceFill(['weight' => $weight])->save();
            $this->audit->append(null, self::owner($owner), 'consumer.weight', ['address' => $address, 'from' => $before, 'to' => $weight]);

            return $consumer->fresh();
        });
    }

    /**
     * A member's project: any of project_name, project_description and
     * project_url. A blank value clears the field. The URL must be absolute
     * http or https. The audit entry names the fields that changed, never their
     * text.
     *
     * @param  array<string, mixed>  $fields
     */
    public function setProject(string $address, array $fields, string $owner): Consumer
    {
        if ($fields === [] || array_diff(array_keys($fields), Consumer::PROJECT_FIELDS) !== []) {
            throw new Refused('invalid_request', 'fields must be among '.implode(', ', Consumer::PROJECT_FIELDS));
        }
        $limits = [
            'project_name' => (int) ($this->settings['project_name_max'] ?? 200),
            'project_description' => (int) ($this->settings['project_description_max'] ?? 5000),
            'project_url' => 2048,
        ];
        $clean = [];
        foreach ($fields as $key => $value) {
            if ($value !== null && ! is_string($value)) {
                throw new Refused('invalid_request', "{$key} must be a string or null");
            }
            $value = $value === null ? null : trim($value);
            $value = $value === '' ? null : $value;
            if ($value !== null && mb_strlen($value) > $limits[$key]) {
                throw new Refused('too_long', "{$key} is longer than {$limits[$key]} characters", ['field' => $key, 'max' => $limits[$key]]);
            }
            if ($key === 'project_url' && $value !== null && ! Consumer::safeUrl($value)) {
                throw new Refused('invalid_url', 'project_url must be an absolute http or https URL');
            }
            $clean[$key] = $value;
        }

        return $this->db->transaction(function () use ($address, $clean, $owner): Consumer {
            $consumer = Consumer::query()->where('address', $address)->lockForUpdate()->first() ?? throw new Refused('not_found', "no consumer {$address}");
            $changed = array_keys(array_filter($clean, fn (?string $v, string $k) => $consumer->getAttribute($k) !== $v, ARRAY_FILTER_USE_BOTH));
            if ($changed !== []) {
                $consumer->forceFill($clean)->save();
                $this->audit->append(null, self::owner($owner), 'consumer.project', ['address' => $address, 'fields' => $changed]);
            }

            return $consumer->fresh();
        });
    }

    /** The council's blocking threshold: an integer from 1 to `council.tally.max_threshold`. */
    public function setBlockingThreshold(mixed $threshold, string $owner): int
    {
        $max = (int) ($this->settings['max_threshold'] ?? 1000);
        if (! is_int($threshold) || $threshold < 1 || $threshold > $max) {
            throw new Refused('invalid_threshold', "threshold must be an integer from 1 to {$max}");
        }

        return $this->db->transaction(function () use ($threshold, $owner): int {
            $before = $this->blockingThreshold();
            Setting::query()->updateOrCreate(['key' => Setting::BLOCKING_THRESHOLD], ['value' => $threshold]);
            $this->audit->append(null, self::owner($owner), 'council.blocking_threshold', ['from' => $before, 'to' => $threshold]);

            return $threshold;
        });
    }

    /**
     * Removes a member from the council. The row is kept (marked `removed_at`),
     * so its voting record and audit history stay readable; it is never
     * deleted. From now on it gets no petitions, leaves the census check and is
     * not re-added by the seeder.
     *
     * Its petitions in rounds that are still OPEN are withdrawn: each is marked
     * `withdrawn_at` and audited as `petition.withdrawn`, and the tally skips
     * it, so it is never silent, never counted and cannot escalate the Change.
     * Petitions in closed rounds are history and stay exactly as tallied.
     */
    public function removeMember(string $address, string $owner, ?string $reason = null): Consumer
    {
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;
        if ($reason !== null && mb_strlen($reason) > 1000) {
            throw new Refused('too_long', 'reason is longer than 1000 characters', ['field' => 'reason', 'max' => 1000]);
        }

        return $this->db->transaction(function () use ($address, $owner, $reason): Consumer {
            $consumer = Consumer::query()->where('address', $address)->lockForUpdate()->first() ?? throw new Refused('not_found', "no consumer {$address}");
            if ($consumer->removed_at !== null) {
                throw new Refused('invalid_state', "{$address} was already removed");
            }
            $now = Carbon::now();
            $consumer->forceFill(['removed_at' => $now])->save();

            $withdrawn = [];
            $open = Petition::query()->where('agent_address', $address)->whereNull('withdrawn_at')->orderBy('id')->get();
            foreach ($open as $petition) {
                $change = Change::query()->lockForUpdate()->find($petition->change_id);
                $round = Round::query()->find($petition->round_id);
                if ($change === null || $round === null || $round->closed_at !== null
                    || ! in_array($change->state, States::OPEN_ROUND, true) || $change->current_round !== $round->number) {
                    continue;
                }
                $petition->forceFill(['withdrawn_at' => $now, 'outcome' => 'withdrawn'])->save();
                $this->audit->append($change->id, self::owner($owner), 'petition.withdrawn', [
                    'petition' => $petition->id, 'to' => $address, 'round' => $round->number, 'tier' => $petition->tier,
                    'because' => 'member_removed', 'counted' => false,
                ]);
                $this->supersede($petition, self::owner($owner), 'member_removed');
                $withdrawn[] = $petition->id;
            }
            $this->audit->append(null, self::owner($owner), 'consumer.removed', ['address' => $address, 'withdrawn' => $withdrawn, 'reason' => $reason]);

            return $consumer->fresh();
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

    /**
     * A verified step-up and the owner action it was for, in ONE transaction:
     * `owner.step_up` is appended, then `$then` runs the owner method. If the
     * action is refused (invalid_state, not_found, ...) the step-up event rolls
     * back with it, so the log never shows a step-up for an action that did not
     * happen. Called only from `Http\Api`, after `StepUp::verify()`.
     *
     * @template T
     *
     * @param  array<string, mixed>  $evidence  what the host's verify() returned
     * @param  Closure(): T  $then
     * @return T
     */
    public function withStepUp(?Change $change, string $owner, OwnerAction $action, array $evidence, Closure $then): mixed
    {
        return $this->db->transaction(function () use ($change, $owner, $action, $evidence, $then): mixed {
            $this->audit->append($change?->id, self::owner($owner), 'owner.step_up', [
                'action' => $action->action, 'ref' => $action->ref, 'address' => $action->address,
                'digest' => $action->digest(), 'evidence' => $evidence,
            ]);

            return $then();
        });
    }

    /** A step-up proof the host refused, audited; the action does not run. */
    public function stepUpRefused(?Change $change, string $owner, OwnerAction $action, string $reason, array $evidence): void
    {
        $this->audit->append($change?->id, self::owner($owner), 'owner.step_up_refused', [
            'action' => $action->action, 'ref' => $action->ref, 'address' => $action->address,
            'digest' => $action->digest(), 'reason' => $reason, 'evidence' => $evidence,
        ]);
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

    /**
     * Counted conflict vote ids in the current round that BLOCK: the ones an
     * owner must acknowledge to approve over them.
     *
     * - A round closed by a 0.5+ tally: exactly what that tally recorded.
     * - An open round: weighed now, against today's weights and threshold.
     * - Anything else (a round a pre-0.5 tally closed, or one closed by an
     *   override or a withdrawal with no tally): every blocking-severity
     *   conflict, the rule that round was decided under. A decided round is
     *   never re-weighed.
     *
     * @return list<int>
     */
    public function openConflicts(Change $change): array
    {
        $round = $change->currentRound();
        if ($round === null) {
            return [];
        }
        if ($round->closed_at !== null && is_array($round->tally) && array_key_exists('threshold', $round->tally)) {
            $ids = array_values(array_map('intval', (array) ($round->tally['blocking'] ?? [])));
            sort($ids);

            return $ids;
        }
        [$petitions, $votes] = $this->countedVotes($round);
        if ($round->closed_at === null) {
            return $this->weigh($petitions, $votes)['blocking'];
        }
        $ids = [];
        foreach ($petitions as $p) {
            $vote = $votes[$p->id];
            if ($vote !== null && $vote->verdict === 'conflict' && in_array($vote->worstSeverity(), self::BLOCKING, true)) {
                $ids[] = $vote->id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * The threshold arithmetic for one round, for the pages: what its tally
     * recorded, or for an open round what it would be now. Null for a round
     * decided before weights existed.
     *
     * @return array{blocking_weight: int, threshold: int, blocks: bool, blocking: list<int>, below_threshold: list<int>, advisory: list<int>, live: bool}|null
     */
    public function weighing(Round $round): ?array
    {
        if (is_array($round->tally) && array_key_exists('threshold', $round->tally)) {
            $t = $round->tally;

            return [
                'blocking_weight' => (int) $t['blocking_weight'], 'threshold' => (int) $t['threshold'], 'blocks' => ($t['blocking'] ?? []) !== [],
                'blocking' => array_values((array) ($t['blocking'] ?? [])), 'below_threshold' => array_values((array) ($t['below_threshold'] ?? [])),
                'advisory' => array_values((array) ($t['advisory'] ?? [])), 'live' => false,
            ];
        }
        if ($round->closed_at !== null) {
            return null;
        }
        [$petitions, $votes] = $this->countedVotes($round);
        $w = $this->weigh($petitions, $votes);

        return [
            'blocking_weight' => $w['blocking_weight'], 'threshold' => $w['threshold'], 'blocks' => $w['blocks'],
            'blocking' => $w['blocking'], 'below_threshold' => $w['below_threshold'], 'advisory' => $w['advisory'], 'live' => true,
        ];
    }

    /** The council's blocking threshold: the owner's setting, else config. */
    public function blockingThreshold(): int
    {
        return $this->threshold()['value'];
    }

    /** @return array{value: int, source: 'owner'|'config'} */
    public function threshold(): array
    {
        $row = Setting::query()->find(Setting::BLOCKING_THRESHOLD);
        if ($row !== null && is_int($row->value) && $row->value >= 1) {
            return ['value' => $row->value, 'source' => 'owner'];
        }

        return ['value' => max(1, (int) ($this->settings['blocking_threshold'] ?? 1)), 'source' => 'config'];
    }

    /**
     * Weighs a round's counted blocking-severity conflicts. Their members'
     * weights are summed; they block only when the sum reaches the threshold.
     * A weight-0 member is advisory and never adds to the sum. A petition with
     * no consumer row counts at the default weight, 1.
     *
     * When weight has to mean something other than a threshold, this is the
     * one place to change: close() and openConflicts() both read it.
     *
     * @param  \Illuminate\Support\Collection<int, Petition>  $petitions
     * @param  array<int, ?Vote>  $votes
     * @return array{blocking_weight: int, threshold: int, blocks: bool, blocking: list<int>, below_threshold: list<int>, advisory: list<int>, weights: array<string, int>}
     */
    private function weigh($petitions, array $votes): array
    {
        $threshold = $this->blockingThreshold();
        $weightOf = Consumer::query()->whereIn('id', $petitions->pluck('consumer_id')->filter()->unique()->values()->all())->pluck('weight', 'id');
        $candidates = [];
        $advisory = [];
        $weights = [];
        $sum = 0;
        foreach ($petitions as $p) {
            $vote = $votes[$p->id] ?? null;
            if ($vote === null || $vote->verdict !== 'conflict' || ! in_array($vote->worstSeverity(), self::BLOCKING, true)) {
                continue;
            }
            $weight = max(0, (int) ($weightOf[$p->consumer_id] ?? 1));
            $weights[$p->agent_address] = $weight;
            if ($weight > 0) {
                $candidates[] = $vote->id;
                $sum += $weight;
            } else {
                $advisory[] = $vote->id;
            }
        }
        sort($candidates);
        sort($advisory);
        $blocks = $candidates !== [] && $sum >= $threshold;

        return [
            'blocking_weight' => $sum, 'threshold' => $threshold, 'blocks' => $blocks,
            'blocking' => $blocks ? $candidates : [], 'below_threshold' => $blocks ? [] : $candidates,
            'advisory' => $advisory, 'weights' => $weights,
        ];
    }

    /** @return array{0: \Illuminate\Support\Collection<int, Petition>, 1: array<int, ?Vote>} */
    private function countedVotes(Round $round): array
    {
        $petitions = Petition::query()->where('round_id', $round->id)->whereNull('withdrawn_at')->orderBy('id')->get();
        $votes = [];
        foreach ($petitions as $p) {
            $votes[$p->id] = $p->countedVote();
        }

        return [$petitions, $votes];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Queues an owner event for AFTER the outermost transaction commits, so a
     * rollback (here, or in a host transaction around us) never notifies. The
     * event is built now, from the state this transaction wrote. Delivery can
     * never fail the decision: it has committed by the time the notifier runs,
     * so anything the notifier throws is swallowed and audited.
     *
     * @param  array<string, int>  $counts
     */
    private function notifyAfterCommit(Change $change, string $event, ?string $from, ?int $round = null, ?string $code = null, array $counts = [], ?string $source = null, ?string $initiator = null): void
    {
        if ($this->notifier instanceof NullNotifier) {
            return;
        }
        $notice = new OwnerEvent($event, (string) $change->ref, (string) $change->id, (string) $change->state, $from, $round, $code, $counts, $source, $initiator);
        $notifier = $this->notifier;
        $audit = $this->audit;
        $deliver = static function () use ($notice, $notifier, $audit): void {
            try {
                $notifier->notify($notice);
            } catch (\Throwable) {
                // Never the exception's message: it may carry a URL or a token.
                try {
                    $audit->append($notice->changeId, 'notifier', 'notify.failed', ['event' => $notice->event, 'ref' => $notice->ref, 'status' => 'exception']);
                } catch (\Throwable) {
                }
            }
        };
        if (! method_exists($this->db, 'afterCommit')) {
            throw new \LogicException('the owner-event notifier needs a connection with afterCommit()');
        }
        $this->db->afterCommit($deliver);
    }

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
