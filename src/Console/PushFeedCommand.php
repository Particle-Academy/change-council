<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Feed\WebhookEmitter;
use Illuminate\Console\Command;

final class PushFeedCommand extends Command
{
    protected $signature = 'council:push-feed';

    protected $description = 'Push the next feed page to the Overwatch webhook (signed; backs off; dead-letters)';

    public function handle(): int
    {
        if (! $this->laravel->bound(WebhookEmitter::class)) {
            $this->line('No council.feed.webhook_url configured; nothing to push.');

            return 0;
        }
        $result = $this->laravel->make(WebhookEmitter::class)->push();
        $this->line(json_encode($result));

        return $result['status'] === 'dead_letter' ? 1 : 0;
    }
}
