<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply, kept as DATA. `raw_text` is exactly what arrived. `reason`, `note`s
 * and `alternative` are the sanitised display copies. Nothing in a vote is ever
 * executed, merged or copied into an approach.
 *
 * @property int $id
 * @property ?string $verdict
 * @property array $impact
 * @property array $flags
 * @property string $status counted | invalid | unsolicited | superseded | late
 * @property ?string $problem
 */
final class Vote extends Model
{
    protected $table = 'council_votes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'impact' => 'array',
            'flags' => 'array',
            'received_at' => 'datetime',
        ];
    }

    public function petition(): BelongsTo
    {
        return $this->belongsTo(Petition::class, 'petition_id');
    }

    /** The most severe impact row: blocker > major > minor, or null. */
    public function worstSeverity(): ?string
    {
        $rank = ['minor' => 1, 'major' => 2, 'blocker' => 3];
        $worst = null;
        foreach ($this->impact as $row) {
            $s = $row['severity'] ?? null;
            if (isset($rank[$s]) && ($worst === null || $rank[$s] > $rank[$worst])) {
                $worst = $s;
            }
        }

        return $worst;
    }
}
