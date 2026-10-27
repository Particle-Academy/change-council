<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One round of petitions. The approach is FROZEN when the round opens: every
 * petition carries its digest, and changing the approach means the next round.
 *
 * @property int $id
 * @property int $number
 * @property string $approach
 * @property string $approach_digest
 * @property ?array $tally
 */
final class Round extends Model
{
    protected $table = 'council_rounds';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tally' => 'array',
            'number' => 'integer',
            'opened_at' => 'datetime',
            'closes_at' => 'datetime',
            'extended_until' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(Change::class, 'change_id');
    }

    public function petitions(): HasMany
    {
        return $this->hasMany(Petition::class, 'round_id')->orderBy('id');
    }

    /** The deadline in force: the extension if one was granted. */
    public function deadline(): \Carbon\CarbonInterface
    {
        return $this->extended_until ?? $this->closes_at;
    }
}
