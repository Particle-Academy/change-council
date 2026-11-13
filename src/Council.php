<?php

declare(strict_types=1);

namespace ChangeCouncil;

use ChangeCouncil\Audit\AuditLog;
use ChangeCouncil\Http\ActorResolver;
use ChangeCouncil\Http\Authorizer;
use ChangeCouncil\Http\CallbackAuthorizer;
use ChangeCouncil\Http\OwnersAuthorizer;
use ChangeCouncil\Laravel\Routes;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Notify\CallbackNotifier;
use ChangeCouncil\Notify\GenieInboxNotifier;
use ChangeCouncil\Notify\NullNotifier;
use ChangeCouncil\Notify\OwnerEvent;
use ChangeCouncil\Notify\OwnerEventNotifier;
use ChangeCouncil\Petitions\Ingest;
use ChangeCouncil\StepUp\NoStepUp;
use ChangeCouncil\StepUp\OwnerAction;
use ChangeCouncil\StepUp\StepUp;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;

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

    /**
     * Who an owner action is recorded as (CC-fancy-0021). The callable gets the
     * authenticated user and returns the id stored in the audit log and the
     * decision, as `owner:<id>` (an answer that already starts with `owner:`
     * is kept as is). Without a hook the id is the user's email, else its key.
     *
     *     Council::resolveActorUsing(fn ($user): string => 'github:'.$user->github_login);
     *
     * The answer must be a non-empty string of at most 191 characters with no
     * control characters or newlines. Anything else refuses the action with
     * `invalid_actor` (HTTP 500); it never falls back to the email.
     *
     * @param  callable(mixed): string  $resolver
     */
    public function resolveActorUsing(callable $resolver): void
    {
        $this->container->instance(ActorResolver::class, new ActorResolver(Closure::fromCallable($resolver)));
    }

    /**
     * Require a step-up proof before OWNER actions (approve, reject, override,
     * revoke, silence policies, weights, projects, member removal, the blocking
     * threshold). The council does not know what the proof is: a passkey
     * ceremony, a TOTP, a second device. Without this, {@see NoStepUp} is bound
     * and nothing changes.
     *
     *     Council::requireStepUpUsing(new MyPasskeyStepUp);
     *
     * The hook's `required()` decides per action; with no proof the API answers
     * 428 `step_up_required` with its `challenge()`; a proof goes to `verify()`,
     * bound to {@see OwnerAction::digest()}. A refusal is audited as
     * `owner.step_up_refused` and answered 403 `step_up_failed`; the evidence of
     * a verified proof is audited as `owner.step_up` in the same transaction as
     * the action.
     */
    public function requireStepUpUsing(StepUp $stepUp): void
    {
        $this->container->instance(StepUp::class, $stepUp);
    }

    /**
     * Be told about owner events (initiation approved/rejected, override,
     * revoke, a round's tally closed, escalation), after commit. Nothing is
     * bound by default. A failure inside the notifier never fails the decision.
     *
     *     Council::notifyUsing(fn (OwnerEvent $event) => ...);
     *
     * @param  OwnerEventNotifier|Closure(OwnerEvent): mixed  $notifier
     */
    public function notifyUsing(OwnerEventNotifier|Closure $notifier): void
    {
        $this->container->instance(OwnerEventNotifier::class, $notifier instanceof Closure ? new CallbackNotifier($notifier) : $notifier);
    }

    /**
     * Owner events to Genie's app inbox (the same as `council.notify.genie`).
     * GENIE_INBOX_URL and GENIE_INBOX_TOKEN are read from the environment at
     * send time; a missing one is a recorded skip, never an error.
     */
    public function notifyGenie(): void
    {
        $this->container->instance(OwnerEventNotifier::class, self::genieNotifier($this->container));
    }

    /** @internal the Genie notifier as configured; shared with the service provider */
    public static function genieNotifier(Container $container): GenieInboxNotifier
    {
        $config = $container->make('config');
        $to = $config->get('council.notify.to');

        return new GenieInboxNotifier(
            $container->make(Factory::class),
            $container->make(AuditLog::class),
            is_string($to) ? $to : null,
            (string) $config->get('council.lab_url', ''),
        );
    }

    /**
     * For a status page: whether a notifier is bound and whether Genie's env
     * is present. Booleans and a class name only, never a value.
     *
     * @return array{bound: bool, notifier: string, env: array{GENIE_INBOX_URL: bool, GENIE_INBOX_TOKEN: bool}}
     */
    public function notifierStatus(): array
    {
        $notifier = $this->container->make(OwnerEventNotifier::class);

        return [
            'bound' => ! $notifier instanceof NullNotifier,
            'notifier' => (new \ReflectionClass($notifier))->getShortName(),
            'env' => GenieInboxNotifier::envPresent(),
        ];
    }

    /** @param array{prefix?: string, middleware?: list<string>} $options */
    public function routes(array $options = []): void
    {
        Routes::register($this->container->make('router'), $options);
    }
}
