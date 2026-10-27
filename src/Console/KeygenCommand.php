<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Records\RecordSigner;
use Illuminate\Console\Command;

/** Prints a new Ed25519 keypair. The secret goes in .env; the public key is published. */
final class KeygenCommand extends Command
{
    protected $signature = 'council:keygen';

    protected $description = 'Generate an Ed25519 keypair for decision records and the feed webhook';

    public function handle(): int
    {
        $pair = RecordSigner::generate();
        $this->line('COUNCIL_SIGNING_KEY='.$pair['secret']);
        $this->line("public key (key_id {$pair['key_id']}): {$pair['public']}");
        $this->line('Keep the secret in .env only. Publish the public key where release gates read it (an owner commit).');

        return 0;
    }
}
