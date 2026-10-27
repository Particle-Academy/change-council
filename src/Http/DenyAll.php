<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

/** The default hook: refuse everything until the host decides otherwise. */
final class DenyAll implements Authorizer
{
    public function allows(mixed $principal, string $ability, array $subject): bool
    {
        return false;
    }
}
