<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

use Closure;

/** `Council::authorizeUsing(fn ($user, string $ability, array $subject): bool => ...)`. Only a literal true allows. */
final class CallbackAuthorizer implements Authorizer
{
    /** @param Closure(mixed, string, array): mixed $callback */
    public function __construct(private readonly Closure $callback) {}

    public function allows(mixed $principal, string $ability, array $subject): bool
    {
        return ($this->callback)($principal, $ability, $subject) === true;
    }
}
