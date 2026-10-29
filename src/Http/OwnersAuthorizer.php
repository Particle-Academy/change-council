<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The opt-in owner-only hook (`Council::authorizeOwners()`): a principal whose
 * email is in `council.owners` may do everything (view, owner, feed); anyone
 * else, nothing.
 *
 * Emails are compared trimmed and case-insensitively, because the list is typed
 * by hand into an env var and a stray capital should not lock the owner out of
 * their own ledger. An EMPTY list allows nobody, and so does a principal with
 * no email: there is no "any signed-in user" fallback.
 *
 * The list is read on every check (through the closure), so a config change
 * applies without re-registering the hook.
 */
final class OwnersAuthorizer implements Authorizer
{
    /** @param Closure(): mixed $owners returns the configured list */
    public function __construct(private readonly Closure $owners) {}

    public function allows(mixed $principal, string $ability, array $subject): bool
    {
        return in_array($ability, self::ABILITIES, true) && $this->includes($principal);
    }

    public function includes(mixed $principal): bool
    {
        $email = self::email($principal);
        if ($email === null) {
            return false;
        }
        foreach ((array) ($this->owners)() as $owner) {
            if (is_string($owner) && trim($owner) !== '' && mb_strtolower(trim($owner)) === $email) {
                return true;
            }
        }

        return false;
    }

    /** The principal's email, trimmed and lower-cased; null when it has none. */
    private static function email(mixed $principal): ?string
    {
        $email = match (true) {
            $principal instanceof Model => $principal->getAttribute('email'),
            is_object($principal) && isset($principal->email) => $principal->email,
            default => null,
        };
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email));
    }
}
