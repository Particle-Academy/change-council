<?php

declare(strict_types=1);

namespace ChangeCouncil\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One COUNCIL-CENSUS-REQUEST v1 to one member, bound to it by a random nonce.
 * Outstanding until answered by a valid census; queued for the clerk, who sends
 * it from its own session (`council:census-requests`).
 *
 * @property int $id
 * @property int $consumer_id
 * @property string $agent_address
 * @property string $nonce
 * @property string $delivery queued | sent | undelivered | unreachable
 * @property ?string $delivery_detail
 * @property ?int $reply_id
 * @property \Illuminate\Support\Carbon $queued_at
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property ?\Illuminate\Support\Carbon $answered_at
 */
final class CensusRequest extends Model
{
    protected $table = 'council_census_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }
}
