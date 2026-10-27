<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A proposed major change to one or more packages. State moves only through
 * {@see \ChangeCouncil\Ledger\Ledger}; nothing else writes `state`.
 *
 * @property string $id
 * @property string $ref
 * @property string $state
 * @property ?int $current_round
 * @property array $classes
 * @property array $packages
 * @property array $evidence
 * @property array $links
 * @property array $silence_overrides
 * @property bool $whitelisted
 */
final class Change extends Model
{
    use HasUlids;

    protected $table = 'council_changes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'classes' => 'array',
            'packages' => 'array',
            'evidence' => 'array',
            'links' => 'array',
            'silence_overrides' => 'array',
            'whitelisted' => 'boolean',
            'approved_unopposed' => 'boolean',
            'approved_with_notes' => 'boolean',
            'late_conflict' => 'boolean',
            'current_round' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class, 'change_id')->orderBy('number');
    }

    public function petitions(): HasMany
    {
        return $this->hasMany(Petition::class, 'change_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'change_id')->orderBy('id');
    }

    public function currentRound(): ?Round
    {
        if ($this->current_round === null) {
            return null;
        }

        return Round::query()->where('change_id', $this->id)->where('number', $this->current_round)->first();
    }
}
