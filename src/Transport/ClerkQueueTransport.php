<?php

declare(strict_types=1);

namespace ChangeCouncil\Transport;

use ChangeCouncil\Models\Petition;

/**
 * v1: nothing is sent from here. The petition stays `queued` until the clerk
 * (the agent that owns the lab) lists it with `council:petitions`, sends it
 * with its own `agentinbox send`, and records the raw tool result with
 * `council:petitions --mark-sent=<id> --result=<json>`.
 */
final class ClerkQueueTransport implements PetitionTransport
{
    public function queue(Petition $petition, string $text): void
    {
        // Deliberately empty: the queue IS the petition row.
    }
}
