<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

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
 * @property string $delivery
 * @property ?string $outcome
 * @property bool $catch_up
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
        ];
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
