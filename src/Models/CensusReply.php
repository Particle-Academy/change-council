<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A COUNCIL-CENSUS v1 reply exactly as received, whatever its fate. Only an
 * `applied` reply ever changed a member, and only by its validated first-party
 * package rows. `apps` is display data.
 *
 * @property int $id
 * @property ?int $request_id
 * @property string $from_address
 * @property array $apps
 * @property array $packages
 * @property array $dropped
 * @property string $raw_text
 * @property array $flags
 * @property string $status applied | invalid | unsolicited
 * @property ?string $problem
 */
final class CensusReply extends Model
{
    protected $table = 'council_census_replies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'apps' => 'array',
            'packages' => 'array',
            'dropped' => 'array',
            'flags' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
