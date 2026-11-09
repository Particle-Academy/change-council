<?php

declare(strict_types=1);

namespace ChangeCouncil\Census;

use ChangeCouncil\Models\CensusReply;

/** applied | invalid | unsolicited | duplicate (a replay: nothing was written). */
final readonly class CensusResult
{
    public function __construct(public string $status, public ?CensusReply $reply, public ?string $problem = null) {}
}
