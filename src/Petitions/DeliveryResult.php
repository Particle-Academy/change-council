<?php

declare(strict_types=1);

namespace ChangeCouncil\Petitions;

/**
 * The clerk's raw `agentinbox send` tool result, read at all three refusal
 * depths (the lesson of tynn.sh and flabs' GenieAgentInbox):
 *
 *   1. a JSON-RPC `error`
 *   2. `result.isError`
 *   3. `{"ok": false, ...}` INSIDE the text payload, after prose
 *
 * Delivery counts as `sent` ONLY when the verdict object says `ok: true`.
 * Anything unconfirmed, unparseable included, is `undelivered`; a refusal that
 * names reachability or permission is `unreachable`.
 */
final readonly class DeliveryResult
{
    public function __construct(public string $delivery, public ?string $detail) {}

    public static function parse(string $raw): self
    {
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return new self('undelivered', 'the result was not JSON; no delivery was confirmed');
        }
        if (isset($body['error'])) {
            return self::refused(is_array($body['error']) ? (string) ($body['error']['message'] ?? 'error') : (string) $body['error']);
        }
        // The bare tool result, as the agentinbox tool hands it to the clerk:
        // `{"ok": true, "delivered": 1, ...}` with no JSON-RPC envelope. The
        // first live round was pasted this way and read as undelivered. The
        // verdict object IS the body; `delivered: 0` is not a delivery.
        if (! array_key_exists('result', $body) && array_key_exists('ok', $body) && ! array_is_list($body)) {
            if ($body['ok'] === true && (! array_key_exists('delivered', $body) || (is_int($body['delivered']) && $body['delivered'] > 0))) {
                return new self('sent', null);
            }

            return self::refused(is_string($body['error'] ?? null) && $body['error'] !== '' ? $body['error'] : 'no delivery was confirmed');
        }
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        $texts = [];
        foreach ((array) ($result['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }
        $verdict = null;
        foreach ($texts as $text) {
            $verdict = self::verdictOf($text) ?? $verdict;
        }
        if (($result['isError'] ?? false) === true) {
            return self::refused(is_string($verdict['error'] ?? null) ? $verdict['error'] : trim((string) strtok($texts[0] ?? 'refused', "\n")));
        }
        if (($verdict['ok'] ?? null) === true) {
            return new self('sent', null);
        }
        $reason = is_string($verdict['error'] ?? null) ? $verdict['error'] : trim((string) strtok($texts[0] ?? '', "\n"));

        return self::refused($reason !== '' ? $reason : 'no delivery was confirmed');
    }

    /** The LAST line of a text that opens a JSON object, decoded from there to the end. */
    private static function verdictOf(string $text): ?array
    {
        $lines = explode("\n", $text);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (! str_starts_with(ltrim($lines[$i]), '{')) {
                continue;
            }
            $decoded = json_decode(implode("\n", array_slice($lines, $i)), true);
            if (is_array($decoded) && ! array_is_list($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private static function refused(string $reason): self
    {
        $unreachable = preg_match('/reachab|permission|scope|asleep|cap reached/i', $reason) === 1;

        return new self($unreachable ? 'unreachable' : 'undelivered', mb_substr($reason, 0, 500));
    }
}
