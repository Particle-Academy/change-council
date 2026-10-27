<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision on a Change: by tally, or by the owner (`owner:<id>`). Owner
 * approvals over conflict list the vote ids acknowledged one by one.
 *
 * @property string $outcome approved | rejected | revoked
 * @property array $acknowledged
 */
final class Decision extends Model
{
    protected $table = 'council_decisions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acknowledged' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(Change::class, 'change_id');
    }
}
