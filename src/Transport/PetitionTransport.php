<?php

declare(strict_types=1);

namespace ChangeCouncil\Transport;

use ChangeCouncil\Models\Petition;

/**
 * Where a queued petition goes. The ledger NEVER calls Genie: the v1
 * implementation, {@see ClerkQueueTransport}, leaves the petition queued for
 * the clerk agent to send from its own session (`council:petitions`). A
 * GenieServiceTransport can drop in here once Genie has a service mailbox.
 */
interface PetitionTransport
{
    public function queue(Petition $petition, string $text): void;
}
