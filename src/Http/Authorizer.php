<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

/**
 * The host-supplied authorization hook every route asks.
 *
 * Abilities:
 *   view   read Changes, rounds, votes, consumers, and the lab pages
 *   owner  owner actions: approve/reject an initiation, override, revoke,
 *          set a silence policy (consumer-wide or per Change)
 *   feed   the Overwatch pull feed (machine reader)
 *
 * The subject is `['ref' => CC-... | null, 'address' => consumer | null]`.
 *
 * The default is {@see DenyAll}: until a host supplies a hook, nothing is
 * allowed. There is no "any signed-in user" default. Only a literal `true`
 * from a callback allows.
 */
interface Authorizer
{
    public const ABILITIES = ['view', 'owner', 'feed'];

    /** @param array{ref?: ?string, address?: ?string} $subject */
    public function allows(mixed $principal, string $ability, array $subject): bool;
}
