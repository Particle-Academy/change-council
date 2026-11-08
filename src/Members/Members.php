<?php

declare(strict_types=1);

namespace ChangeCouncil\Members;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\Models\Petition;
use ChangeCouncil\Models\Round;
use ChangeCouncil\Models\Vote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Council members (the consumer rows) as the lab and the API show them: the
 * profile, vote counts and the voting record. Read-only; every write is a
 * ledger owner method.
 *
 * A petition's vote is its latest `counted` vote, else its latest `late` one
 * (a catch-up answer after the round closed). A petition with neither is
 * `missed` once its outcome is silent, unreachable, undelivered or invalid,
 * and `pending` until then.
 */
final class Members
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * The current members: every row the owner has not removed.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->rows(Consumer::query()->whereNull('removed_at')->orderBy('address')->get());
    }

    /**
     * Former members: rows the owner removed. Kept, with their record, never petitioned.
     *
     * @return list<array<string, mixed>>
     */
    public function former(): array
    {
        return $this->rows(Consumer::query()->whereNotNull('removed_at')->orderBy('address')->get());
    }

    /**
     * @param  Collection<int, Consumer>  $consumers
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $consumers): array
    {
        $petitions = Petition::query()->whereIn('agent_address', $consumers->pluck('address')->all())->get();
        $votes = $this->votesFor($petitions);
        $byAddress = $petitions->groupBy('agent_address');

        return $consumers->map(fn (Consumer $c) => $this->profile($c, $this->stats($byAddress->get($c->address, collect()), $votes)))->values()->all();
    }

    public function find(string $address): ?Consumer
    {
        return Consumer::query()->where('address', $address)->first();
    }

    /** @return array<string, mixed> */
    public function profile(Consumer $c, ?array $stats = null): array
    {
        if ($stats === null) {
            $petitions = Petition::query()->where('agent_address', $c->address)->get();
            $stats = $this->stats($petitions, $this->votesFor($petitions));
        }

        return [
            'address' => $c->address,
            'name' => self::name($c->address),
            'workspace' => $c->workspace,
            'project_name' => $c->project_name,
            'project_description' => $c->project_description,
            // Defence in depth: the ledger refuses anything else on write, but a
            // row can be written another way (a seed, a hand edit).
            'project_url' => Consumer::safeUrl($c->project_url) ? $c->project_url : null,
            'weight' => (int) ($c->weight ?? 1),
            'silence_policy' => $c->silence_policy,
            'whitelisted' => (bool) $c->whitelisted,
            'internal' => (bool) $c->internal,
            'source' => $c->source,
            'census_at' => self::at($c->census_at),
            'reachable' => $c->reachable,
            'notes' => $c->notes,
            'packages' => $c->packages,
            'watches' => $c->watches,
            'package_count' => count((array) $c->packages),
            'stats' => $stats,
            'removed_at' => self::at($c->removed_at),
        ];
    }

    /**
     * Every petition sent to the member, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function record(Consumer $c): array
    {
        $petitions = Petition::query()->where('agent_address', $c->address)->orderByDesc('queued_at')->orderByDesc('id')->get();
        $votes = $this->votesFor($petitions);
        $changes = Change::query()->whereIn('id', $petitions->pluck('change_id')->unique()->values()->all())->get()->keyBy('id');
        $rounds = Round::query()->whereIn('id', $petitions->pluck('round_id')->unique()->values()->all())->pluck('number', 'id');

        return $petitions->map(function (Petition $p) use ($votes, $changes, $rounds): array {
            $vote = $votes[$p->id] ?? null;
            $change = $changes[$p->change_id] ?? null;

            return [
                'petition' => $p->id,
                'change' => ['ref' => $change?->ref, 'title' => $change?->title, 'state' => $change?->state],
                'round' => (int) ($rounds[$p->round_id] ?? 0),
                'tier' => $p->tier,
                'delivery' => $p->delivery,
                'outcome' => $p->outcome,
                'verdict' => $vote?->verdict ?? ($p->withdrawn_at !== null ? 'withdrawn' : null) ?? $p->outcome ?? (in_array($p->delivery, ['undelivered', 'unreachable'], true) ? $p->delivery : null),
                'vote_status' => $vote?->status,
                'late' => $vote?->status === 'late',
                'reason' => $vote?->reason,
                'impact' => $vote?->impact ?? [],
                'severity' => $vote?->worstSeverity(),
                'queued_at' => self::at($p->queued_at),
                'sent_at' => self::at($p->sent_at),
                'replied_at' => self::at($vote?->received_at),
                'withdrawn' => $p->withdrawn_at !== null,
            ];
        })->values()->all();
    }

    /** @return array{value: int, source: string} */
    public function threshold(): array
    {
        return $this->ledger->threshold();
    }

    /**
     * @param  Collection<int, Petition>  $petitions
     * @param  array<int, Vote>  $votes
     * @return array{petitions: int, cast: int, approve: int, conflict: int, abstain: int, missed: int, pending: int, withdrawn: int, last_vote_at: ?string}
     */
    private function stats(Collection $petitions, array $votes): array
    {
        $stats = ['petitions' => $petitions->count(), 'cast' => 0, 'approve' => 0, 'conflict' => 0, 'abstain' => 0, 'missed' => 0, 'pending' => 0, 'withdrawn' => 0, 'last_vote_at' => null];
        $last = null;
        foreach ($petitions as $p) {
            $vote = $votes[$p->id] ?? null;
            if ($vote !== null) {
                $stats['cast']++;
                if (isset($stats[$vote->verdict])) {
                    $stats[$vote->verdict]++;
                }
                if ($vote->received_at !== null && ($last === null || $vote->received_at->getTimestamp() > $last->getTimestamp())) {
                    $last = $vote->received_at;
                }
            } elseif ($p->withdrawn_at !== null) {
                // Its member was removed while the round was open: not missed.
                $stats['withdrawn']++;
            } elseif (in_array($p->outcome, Ledger::SILENT_OUTCOMES, true)) {
                $stats['missed']++;
            } else {
                $stats['pending']++;
            }
        }
        $stats['last_vote_at'] = self::at($last);

        return $stats;
    }

    /**
     * The vote that stands for each petition: latest counted, else latest late.
     *
     * @param  Collection<int, Petition>  $petitions
     * @return array<int, Vote>
     */
    private function votesFor(Collection $petitions): array
    {
        $out = [];
        $all = Vote::query()->whereIn('petition_id', $petitions->pluck('id')->all())->whereIn('status', ['counted', 'late'])->orderBy('id')->get();
        foreach ($all->groupBy('petition_id') as $petitionId => $votes) {
            $out[(int) $petitionId] = $votes->where('status', 'counted')->last() ?? $votes->last();
        }

        return $out;
    }

    private static function name(string $address): string
    {
        $at = strpos($address, '@');

        return $at === false || $at === 0 ? $address : substr($address, 0, $at);
    }

    private static function at(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
