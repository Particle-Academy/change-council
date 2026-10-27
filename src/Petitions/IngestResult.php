<?php

declare(strict_types=1);

namespace ChangeCouncil\Petitions;

use ChangeCouncil\Models\Vote;

/** counted | late | invalid | unsolicited | duplicate (a replay: nothing was written). */
final readonly class IngestResult
{
    public function __construct(public string $status, public ?Vote $vote, public ?string $problem = null) {}
}
