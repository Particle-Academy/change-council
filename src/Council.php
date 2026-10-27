<?php

declare(strict_types=1);

namespace ChangeCouncil;

use ChangeCouncil\Http\Authorizer;
use ChangeCouncil\Http\CallbackAuthorizer;
use ChangeCouncil\Laravel\Routes;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Petitions\Ingest;
use Closure;
use Illuminate\Contracts\Container\Container;

/** The host-facing entry point (facade {@see Facades\Council}). */
final class Council
{
    public function __construct(private readonly Container $container) {}

    public function ledger(): Ledger
    {
        return $this->container->make(Ledger::class);
    }

    public function ingest(): Ingest
    {
        return $this->container->make(Ingest::class);
    }

    /**
     * Who may do what. Abilities: view, owner, feed (see {@see Authorizer}).
     * Until this is called every route answers 403.
     *
     *     Council::authorizeUsing(fn ($user, string $ability, array $subject): bool =>
     *         $ability === 'owner' ? $user->can('council-owner') : $user !== null);
     *
     * @param  Closure(mixed, string, array): bool  $callback
     */
    public function authorizeUsing(Closure $callback): void
    {
        $this->container->instance(Authorizer::class, new CallbackAuthorizer($callback));
    }

    /** @param array{prefix?: string, middleware?: list<string>} $options */
    public function routes(array $options = []): void
    {
        Routes::register($this->container->make('router'), $options);
    }
}
