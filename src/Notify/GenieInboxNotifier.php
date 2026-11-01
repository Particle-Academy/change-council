<?php

declare(strict_types=1);

namespace ChangeCouncil\Notify;

use ChangeCouncil\Audit\AuditLog;
use Illuminate\Http\Client\Factory;

/**
 * Owner events to Genie's app inbox:
 *
 *   POST {GENIE_INBOX_URL}/v1/inbox   Authorization: Bearer {GENIE_INBOX_TOKEN}
 *   {to?, subject, body, level, link?, dedupeKey: "council:{ref}:{event}"}
 *
 * The URL and token are read with getenv() AT SEND TIME and never stored:
 * Genie issues a new token on every restart and on Rotate/Revoke, and
 * `config:cache` would freeze a value read through config. Neither is ever
 * written to the audit log, an exception or a log line.
 *
 * Every outcome is an audit event (`notify.sent`, `notify.failed`,
 * `notify.skipped`) carrying the event, the ref and a status ONLY. Nothing is
 * thrown and nothing is retried inside the request: a 404 means the token is
 * unknown, rotated or revoked (retrying with it cannot work), and a 429 records
 * its Retry-After for whoever reads the log.
 */
final class GenieInboxNotifier implements OwnerEventNotifier
{
    public const URL_ENV = 'GENIE_INBOX_URL';

    public const TOKEN_ENV = 'GENIE_INBOX_TOKEN';

    public const MAX_BODY_BYTES = 8192;

    private const SUCCESS = [OwnerEvent::INITIATION_APPROVED, OwnerEvent::OVERRIDE_APPROVE];

    public function __construct(
        private readonly Factory $http,
        private readonly AuditLog $audit,
        private readonly ?string $to = null,
        private readonly string $labUrl = '',
    ) {}

    /** @return array{GENIE_INBOX_URL: bool, GENIE_INBOX_TOKEN: bool} presence only, never the values */
    public static function envPresent(): array
    {
        return [self::URL_ENV => self::env(self::URL_ENV) !== null, self::TOKEN_ENV => self::env(self::TOKEN_ENV) !== null];
    }

    public function notify(OwnerEvent $event): void
    {
        $url = self::env(self::URL_ENV);
        $token = self::env(self::TOKEN_ENV);
        if ($url === null || $token === null) {
            $this->record($event, 'notify.skipped', ['status' => 'env_missing']);

            return;
        }

        try {
            $response = $this->http->withToken($token)->acceptJson()->asJson()
                ->connectTimeout(2)->timeout(5)
                ->post(rtrim($url, '/').'/v1/inbox', $this->payload($event));
        } catch (\Throwable) {
            // The message names the URL; it is deliberately not recorded.
            $this->record($event, 'notify.failed', ['status' => 0, 'error' => 'network']);

            return;
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            $this->record($event, 'notify.sent', ['status' => $status]);

            return;
        }
        $extra = ['status' => $status];
        if ($status === 429) {
            $extra['retry_after'] = self::retryAfter($response->header('Retry-After'));
        }
        $this->record($event, 'notify.failed', $extra);
    }

    /** @return array<string, string> */
    public function payload(OwnerEvent $event): array
    {
        $link = $this->link($event);
        $body = [];
        if ($this->to !== null && trim($this->to) !== '') {
            $body['to'] = trim($this->to);
        }
        $body['subject'] = 'Change Council '.$event->ref.': '.$event->label().($event->needsOwner() ? ' (owner decision needed)' : '');
        $body['body'] = self::cap(self::text($event, $link));
        $body['level'] = match (true) {
            in_array($event->event, self::SUCCESS, true) => 'success',
            $event->event === OwnerEvent::ESCALATED => 'failure',
            default => 'info',
        };
        if ($link !== null) {
            $body['link'] = $link;
        }
        $body['dedupeKey'] = $event->dedupeKey();

        return $body;
    }

    /**
     * Refs, states, codes and counts, plus the source and initiator identity
     * on `awaiting_owner`. Never a title, summary, approach, pros/cons, note,
     * reason or reply.
     */
    public static function text(OwnerEvent $event, ?string $link): string
    {
        $lines = ['Change Council '.$event->ref.': '.$event->label(), 'state: '.$event->state];
        if ($event->fromState !== null) {
            $lines[] = 'from: '.$event->fromState;
        }
        if ($event->round !== null) {
            $lines[] = 'round: '.$event->round;
        }
        if ($event->code !== null) {
            $lines[] = 'reason code: '.$event->code;
        }
        if ($event->source !== null) {
            $lines[] = 'source: '.$event->source;
        }
        if ($event->initiator !== null) {
            $lines[] = 'initiator: '.OwnerEvent::identity($event->initiator);
        }
        if ($event->counts !== []) {
            $lines[] = 'counts: '.implode(' ', array_map(fn (string $k, int $v) => $k.'='.$v, array_keys($event->counts), $event->counts));
        }
        if ($event->event === OwnerEvent::AWAITING_OWNER) {
            $lines[] = 'The owner must decide: approve or reject this initiation in the lab.';
        } elseif ($event->event === OwnerEvent::ESCALATED) {
            $lines[] = 'The owner must decide: approve or reject this Change in the lab.';
        }
        $lines[] = $link === null ? 'Read it in the lab.' : 'Read it in the lab: '.$link;

        return implode("\n", $lines);
    }

    /** At most 8 KB, cut on a UTF-8 character boundary. */
    public static function cap(string $text): string
    {
        return strlen($text) <= self::MAX_BODY_BYTES ? $text : mb_strcut($text, 0, self::MAX_BODY_BYTES, 'UTF-8');
    }

    private function link(OwnerEvent $event): ?string
    {
        $base = trim($this->labUrl);

        return $base === '' ? null : rtrim($base, '/').'/changes/'.$event->ref;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Seconds, or null when the header is absent or not a plain integer. */
    private static function retryAfter(string $header): ?int
    {
        return preg_match('/^\s*(\d{1,6})\s*$/', $header, $m) === 1 ? (int) $m[1] : null;
    }

    /** @param array<string, int|string|null> $extra */
    private function record(OwnerEvent $event, string $type, array $extra): void
    {
        $this->audit->append($event->changeId, 'notifier', $type, ['event' => $event->event, 'ref' => $event->ref, ...$extra]);
    }
}
