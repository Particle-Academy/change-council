<?php

declare(strict_types=1);

namespace ChangeCouncil;

use ChangeCouncil\Http\Authorizer;
use ChangeCouncil\Http\CallbackAuthorizer;
use ChangeCouncil\Http\OwnersAuthorizer;
use ChangeCouncil\Laravel\Routes;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Petitions\Ingest;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
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
     * Until this (or {@see authorizeOwners()}) is called every route answers 403.
     *
     *     Council::authorizeUsing(fn ($user, string $ability, array $subject): bool =>
     *         $user?->can('council-owner') === true);
     *
     * Do not allow `view` for any signed-in user: on a host with open
     * registration that hands the ledger to anyone who registers.
     *
     * @param  Closure(mixed, string, array): bool  $callback
     */
    public function authorizeUsing(Closure $callback): void
    {
        $this->container->instance(Authorizer::class, new CallbackAuthorizer($callback));
    }

    /**
     * The owner-only default: users whose email is in `council.owners`
     * (env COUNCIL_OWNERS) get every ability, everyone else none, and an empty
     * list allows nobody. Also defines the `council-owner` Gate, unless the
     * host already defined one, so the host can draw its own link with
     * `$user->can('council-owner')`.
     */
    public function authorizeOwners(): void
    {
        $config = $this->container->make('config');
        $authorizer = new OwnersAuthorizer(static fn (): mixed => $config->get('council.owners', []));
        $this->container->instance(Authorizer::class, $authorizer);

        if ($this->container->bound(Gate::class)) {
            $gate = $this->container->make(Gate::class);
            if (! $gate->has('council-owner')) {
                $gate->define('council-owner', static fn (mixed $user): bool => $authorizer->includes($user));
            }
        }
    }

    /** @param array{prefix?: string, middleware?: list<string>} $options */
    public function routes(array $options = []): void
    {
        Routes::register($this->container->make('router'), $options);
    }
}
