<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use ChangeCouncil\Ledger\States;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One consumer's petition in one round, bound to it by a random nonce.
 *
 * @property int $id
 * @property string $agent_address
 * @property string $tier
 * @property string $nonce
 * @property string $delivery queued | sent | undelivered | unreachable | superseded (never sent: its round closed, its Change was decided or withdrawn, or its member was removed)
 * @property ?string $outcome
 * @property bool $catch_up
 * @property ?\Illuminate\Support\Carbon $withdrawn_at its member was removed while the round was open
 */
final class Petition extends Model
{
    protected $table = 'council_petitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'catch_up' => 'boolean',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * THE definition of a petition the clerk can still send: queued, not
     * withdrawn, its round not closed, its Change in an open round. Every
     * count or list of "queued" petitions goes through this, so the page and
     * `council:petitions` can never disagree.
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->where('delivery', 'queued')
            ->whereNull('withdrawn_at')
            ->whereHas('round', fn (Builder $q) => $q->whereNull('closed_at'))
            ->whereHas('change', fn (Builder $q) => $q->whereIn('state', States::OPEN_ROUND));
    }

    /**
     * Queued, can never be sent, and never answered: what the ledger records
     * as `superseded`. A petition with any reply reached its member, whatever
     * the clerk recorded, so it is not superseded.
     */
    public function scopeStaleQueued(Builder $query): Builder
    {
        return $query->where('delivery', 'queued')
            ->whereNotIn('id', self::query()->sendable()->select('id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('council_votes')->whereColumn('council_votes.petition_id', 'council_petitions.id'));
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class, 'round_id');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(Change::class, 'change_id');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class, 'consumer_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class, 'petition_id')->orderBy('id');
    }

    public function countedVote(): ?Vote
    {
        return Vote::query()->where('petition_id', $this->id)->where('status', 'counted')->latest('id')->first();
    }
}
