<?php

declare(strict_types=1);

namespace ChangeCouncil\Feed;

use ChangeCouncil\Records\RecordSigner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The Overwatch push: the next feed page, POSTed with
 *
 *   Council-Key-Id: <key_id>
 *   Council-Signature: t=<unix>,ed25519=<base64 signature over "<t>.<body>">
 *
 * A 2xx advances the cursor. Anything else backs off (2^attempts minutes,
 * capped at 60) and, after `max_attempts`, dead-letters: it stops and the
 * dashboard shows it, rather than retrying forever or quietly giving up.
 * Run from the scheduler: `council:push-feed`.
 */
final class WebhookEmitter
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly Feed $feed,
        private readonly RecordSigner $signer,
        private readonly HttpSender $sender,
        private readonly ConnectionInterface $db,
        private readonly string $url,
        private readonly int $maxAttempts,
        private readonly int $batch,
    ) {}

    /** @return array{status: string, sent?: int, cursor?: int} */
    public function push(): array
    {
        $now = Carbon::now();
        $state = $this->state();
        if ($state->dead_lettered_at !== null) {
            return ['status' => 'dead_letter'];
        }
        if ($state->next_attempt_at !== null && $now->lessThan(Carbon::parse($state->next_attempt_at))) {
            return ['status' => 'backing_off'];
        }
        $page = $this->feed->page((int) $state->last_seq, $this->batch);
        if ($page['events'] === []) {
            return ['status' => 'nothing_new'];
        }
        $body = json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $t = (string) $now->getTimestamp();
        $status = $this->sender->post($this->url, [
            'Content-Type' => 'application/json',
            'Council-Key-Id' => $this->signer->keyId(),
            'Council-Signature' => 't='.$t.',ed25519='.$this->signer->signBytes($t.'.'.$body),
        ], $body);

        if ($status >= 200 && $status < 300) {
            $this->update(['last_seq' => $page['cursor'], 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null]);

            return ['status' => 'delivered', 'sent' => count($page['events']), 'cursor' => $page['cursor']];
        }
        $attempts = (int) $state->attempts + 1;
        if ($attempts >= $this->maxAttempts) {
            $this->update(['attempts' => $attempts, 'last_error' => "HTTP {$status}", 'dead_lettered_at' => $now]);

            return ['status' => 'dead_letter'];
        }
        $this->update(['attempts' => $attempts, 'last_error' => "HTTP {$status}", 'next_attempt_at' => $now->copy()->addMinutes(min(60, 2 ** $attempts))]);

        return ['status' => 'retrying'];
    }

    /** For a receiver (and the tests): checks the header against the body and the clock. */
    public static function verify(string $header, string $body, string $rawPublicKey, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        if (preg_match('/^t=(\d+),ed25519=([A-Za-z0-9+\/=]+)$/', $header, $m) !== 1) {
            return false;
        }
        if (abs($now - (int) $m[1]) > $tolerance) {
            return false;
        }

        return RecordSigner::verifyBytes($m[1].'.'.$body, $m[2], $rawPublicKey);
    }

    private function state(): object
    {
        $row = $this->db->table('council_webhooks')->where('url', $this->url)->first();
        if ($row === null) {
            $this->db->table('council_webhooks')->insert(['url' => $this->url, 'last_seq' => 0, 'attempts' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            $row = $this->db->table('council_webhooks')->where('url', $this->url)->first();
        }

        return $row;
    }

    private function update(array $values): void
    {
        $this->db->table('council_webhooks')->where('url', $this->url)->update([...$values, 'updated_at' => Carbon::now()]);
    }
}
