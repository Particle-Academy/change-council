<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A council-level owner setting (key => JSON value). Written only by the
 * ledger's owner methods, each audited. A key with no row falls back to config.
 *
 * @property string $key
 * @property mixed $value
 */
final class Setting extends Model
{
    public const BLOCKING_THRESHOLD = 'blocking_threshold';

    protected $table = 'council_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
