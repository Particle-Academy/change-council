<?php

declare(strict_types=1);

namespace ChangeCouncil\Tests\Support;

use ChangeCouncil\Models\Petition;
use ChangeCouncil\Transport\PetitionTransport;

/**
 * Records what the ledger queued and sends nothing. A test that wants a
 * delivery outcome scripts it through `council:petitions --mark-sent` (the
 * clerk's path) rather than through this class, so the parsing of a real tool
 * result is what gets exercised.
 */
final class FakeTransport implements PetitionTransport
{
    /** @var list<array{petition: int, to: string, text: string}> */
    public array $queued = [];

    public function queue(Petition $petition, string $text): void
    {
        $this->queued[] = ['petition' => $petition->id, 'to' => $petition->agent_address, 'text' => $text];
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_values(array_unique(array_column($this->queued, 'to')));
    }
}
