<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One row of the append-only, hash-chained audit log. Written ONLY through
 * {@see \ChangeCouncil\Audit\AuditLog::append()}. Updating or deleting through
 * the model throws; on SQLite a trigger refuses it underneath as well.
 *
 * @property int $seq
 * @property ?string $change_id
 * @property string $at
 * @property string $actor
 * @property string $type
 * @property array $data
 * @property string $prev_hash
 * @property string $hash
 */
final class AuditEvent extends Model
{
    protected $table = 'council_events';

    protected $primaryKey = 'seq';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'seq' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('council_events is append-only'));
        static::deleting(fn () => throw new LogicException('council_events is append-only'));
    }
}
