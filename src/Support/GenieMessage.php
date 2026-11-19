<?php

declare(strict_types=1);

namespace ChangeCouncil\Support;

use ChangeCouncil\Ledger\Ledger;

/**
 * Reads the raw message JSON the clerk pipes from `agentinbox receive`, and
 * turns its sender into the address a petition or census request was sent to.
 *
 * Genie stamps `from` with the sender's AGENT ID (`agent_<hex>`), while the
 * council addresses members as `name@workspace`, and Genie's message carries
 * no address field today. So the sender is resolved in this order:
 *
 *   1. an address field on the message (`fromAddress`), when it is a valid
 *      `name@workspace`. Genie does not send one yet; a malformed one is
 *      ignored, never trusted;
 *   2. the host's map `council.genie.agent_addresses` (agent id => address);
 *   3. `from` as it is.
 *
 * The sender check itself is unchanged: whatever this resolves must still equal
 * the petitioned address exactly. An agent id nobody mapped is refused as
 * `unmapped_agent`, so the clerk can see what to fix.
 */
final class GenieMessage
{
    public const AGENT_ID = '/^agent_[A-Za-z0-9]{1,64}$/D';

    public const CONFIG = 'council.genie.agent_addresses';

    public static function isAgentId(string $from): bool
    {
        return preg_match(self::AGENT_ID, $from) === 1;
    }

    /**
     * The host's agent-id map, validated. Null or absent is an empty map. Any
     * bad entry throws, naming it, so a typo fails at boot and not at the
     * first reply.
     *
     * @return array<string, string>
     */
    public static function agentMap(mixed $map): array
    {
        if ($map === null) {
            return [];
        }
        if (! is_array($map)) {
            throw new \InvalidArgumentException(self::CONFIG.' must be a map of Genie agent id => name@workspace address.');
        }
        $valid = [];
        foreach ($map as $agent => $address) {
            $key = (string) $agent;
            if (! is_string($agent) || ! self::isAgentId($agent)) {
                throw new \InvalidArgumentException(self::CONFIG.': key "'.$key.'" is not a Genie agent id (agent_<id>).');
            }
            if (! Ledger::validAddress($address)) {
                throw new \InvalidArgumentException(self::CONFIG.'["'.$key.'"] is not a name@workspace address.');
            }
            $valid[$agent] = $address;
        }

        return $valid;
    }

    /** The address a sender stands for: mapped when it is a mapped agent id, else unchanged. */
    public static function resolve(string $from, array $agents): string
    {
        return self::isAgentId($from) && isset($agents[$from]) ? $agents[$from] : $from;
    }

    /**
     * @param  array<string, string>  $agents
     * @return array{from: string, text: string, id: ?string, at: ?string}
     */
    public static function read(string $json, array $agents): array
    {
        $message = json_decode($json, true);
        if (! is_array($message) || ! is_string($message['from'] ?? null) || ! is_string($message['text'] ?? null)) {
            throw new \InvalidArgumentException('Expected the raw Genie message JSON: {"id", "from", "text", "sentAt"}.');
        }
        $address = $message['fromAddress'] ?? null;
        $from = Ledger::validAddress($address) ? $address : self::resolve($message['from'], $agents);
        $at = $message['at'] ?? $message['sentAt'] ?? null;

        return [
            'from' => $from,
            'text' => $message['text'],
            'id' => is_scalar($message['id'] ?? null) ? (string) $message['id'] : null,
            'at' => is_string($at) ? $at : null,
        ];
    }
}
