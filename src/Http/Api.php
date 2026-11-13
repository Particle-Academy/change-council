<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

use ChangeCouncil\Feed\Feed;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Members\Members;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use ChangeCouncil\StepUp\NoStepUp;
use ChangeCouncil\StepUp\OwnerAction;
use ChangeCouncil\StepUp\StepUp;
use ChangeCouncil\StepUp\StepUpRefused;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The transport contract without a framework (charlotte's shape): each method
 * takes the authenticated principal and the decoded body, and returns a status
 * plus JSON. Order of checks: principal present, Change found, then the
 * authorization hook for the ability. Owner actions record `owner:<identity>`,
 * where the identity is the principal's email when it has one, else its key,
 * unless the host maps it with `Council::resolveActorUsing()` ({@see ActorResolver}).
 *
 * Owner actions then pass the step-up gate ({@see StepUp}, bound with
 * `Council::requireStepUpUsing()`): body validation, the actor, the gate, the
 * ledger. The body's reserved `step_up` key is split off before validation.
 */
final class Api
{
    public function __construct(private readonly Ledger $ledger, private readonly Presenter $presenter, private readonly Feed $feed, private readonly Authorizer $authorizer, private readonly ?Members $members = null, private readonly ?ActorResolver $actors = null, private readonly ?StepUp $stepUp = null) {}

    private function members(): Members
    {
        return $this->members ?? new Members($this->ledger);
    }

    /** Who an owner action is recorded as; throws Refused(invalid_actor) on a bad hook answer. */
    private function actor(mixed $principal): string
    {
        return ($this->actors ?? new ActorResolver)->resolve($principal);
    }

    /** GET /members: every current member with its counts, former (removed) members apart, and the council's blocking threshold. */
    public function memberList(mixed $principal): ApiResponse
    {
        return $this->allowed($principal, 'view', ['ref' => null, 'address' => null], fn () => new ApiResponse(200, [
            'members' => $this->members()->list(),
            'blocking_threshold' => $this->ledger->threshold(),
            'former_members' => $this->members()->former(),
        ]));
    }

    /**
     * POST /members/{address}/remove {reason?}: the owner removes a member from
     * the council. The row and its history are kept; its open petitions are
     * withdrawn. See {@see Ledger::removeMember()}.
     */
    public function removeMember(mixed $principal, string $address, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);
        if (is_array($body) && array_key_exists('reason', $body) && $body['reason'] !== null && ! is_string($body['reason'])) {
            $body = null;
        }

        return $this->memberAction($principal, $address, $body, $proof, OwnerAction::MEMBER_REMOVE, [], ['reason'], fn (array $b, string $owner) => $this->ledger->removeMember($address, $owner, $b['reason'] ?? null));
    }

    /** GET /members/{address}: the profile and the voting record, newest first. */
    public function member(mixed $principal, string $address): ApiResponse
    {
        return $this->allowed($principal, 'view', ['ref' => null, 'address' => $address], function () use ($address): ApiResponse {
            $consumer = $this->members()->find($address) ?? throw new Refused('not_found');

            return new ApiResponse(200, [
                'member' => $this->members()->profile($consumer),
                'record' => $this->members()->record($consumer),
                'blocking_threshold' => $this->ledger->threshold(),
            ]);
        });
    }

    /** PUT /members/{address}/weight {weight} */
    public function memberWeight(mixed $principal, string $address, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);

        return $this->memberAction($principal, $address, $body, $proof, OwnerAction::MEMBER_WEIGHT, ['weight'], [], fn (array $b, string $owner) => $this->ledger->setWeight($address, $b['weight'], $owner));
    }

    /** PUT /members/{address}/project {project_name?, project_description?, project_url?} */
    public function memberProject(mixed $principal, string $address, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);

        return $this->memberAction($principal, $address, $body, $proof, OwnerAction::MEMBER_PROJECT, [], Consumer::PROJECT_FIELDS, fn (array $b, string $owner) => $this->ledger->setProject($address, $b, $owner));
    }

    /** PUT /settings/blocking-threshold {threshold} */
    public function blockingThreshold(mixed $principal, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);

        return $this->allowed($principal, 'owner', ['ref' => null, 'address' => null], function () use ($principal, $body, $proof): ApiResponse {
            if (! self::closed($body, ['threshold'])) {
                return self::error(422, 'invalid_request');
            }
            $owner = $this->actor($principal);
            $stopped = $this->stepUpGate($principal, null, $owner, new OwnerAction(OwnerAction::BLOCKING_THRESHOLD, null, null, $body), $proof, fn () => $this->ledger->setBlockingThreshold($body['threshold'], $owner));
            if ($stopped instanceof ApiResponse) {
                return $stopped;
            }

            return new ApiResponse(200, ['blocking_threshold' => $this->ledger->threshold()]);
        });
    }

    private function memberAction(mixed $principal, string $address, ?array $body, ?array $proof, string $name, array $required, array $optional, Closure $action): ApiResponse
    {
        return $this->allowed($principal, 'owner', ['ref' => null, 'address' => $address], function () use ($principal, $address, $body, $proof, $name, $required, $optional, $action): ApiResponse {
            if (! self::closed($body, $required, $optional)) {
                return self::error(422, 'invalid_request');
            }
            $owner = $this->actor($principal);
            $stopped = $this->stepUpGate($principal, null, $owner, new OwnerAction($name, null, $address, $body), $proof, fn () => $action($body, $owner));
            if ($stopped instanceof ApiResponse) {
                return $stopped;
            }
            $consumer = $this->members()->find($address) ?? throw new Refused('not_found');

            return new ApiResponse(200, ['member' => $this->members()->profile($consumer)]);
        });
    }

    /** GET /changes?state=&package=&class= */
    public function changes(mixed $principal, array $query): ApiResponse
    {
        return $this->allowed($principal, 'view', ['ref' => null, 'address' => null], function () use ($query): ApiResponse {
            $builder = Change::query()->orderByDesc('created_at')->orderByDesc('ref');
            if (is_string($query['state'] ?? null) && in_array($query['state'], States::ALL, true)) {
                $builder->where('state', $query['state']);
            }
            $changes = $builder->limit(500)->get()->filter(function (Change $c) use ($query): bool {
                if (is_string($query['package'] ?? null) && $query['package'] !== '' && ! in_array($query['package'], array_column($c->packages, 'name'), true)) {
                    return false;
                }

                return ! (is_string($query['class'] ?? null) && $query['class'] !== '' && ! in_array($query['class'], $c->classes, true));
            });

            return new ApiResponse(200, ['changes' => $changes->map(fn (Change $c) => $this->presenter->summary($c))->values()->all()]);
        });
    }

    /** GET /changes/{ref} */
    public function change(mixed $principal, string $ref): ApiResponse
    {
        return $this->withChange($principal, $ref, 'view', fn (Change $c) => new ApiResponse(200, $this->presenter->detail($c)));
    }

    /** GET /changes/{ref}/rounds */
    public function rounds(mixed $principal, string $ref): ApiResponse
    {
        return $this->withChange($principal, $ref, 'view', fn (Change $c) => new ApiResponse(200, ['rounds' => $this->presenter->detail($c)['rounds']]));
    }

    /** GET /consumers */
    public function consumers(mixed $principal): ApiResponse
    {
        return $this->allowed($principal, 'view', ['ref' => null, 'address' => null], fn () => new ApiResponse(200, [
            'consumers' => Consumer::query()->orderBy('address')->get()->map(fn (Consumer $c) => Presenter::consumer($c))->all(),
        ]));
    }

    /** GET /feed?since= */
    public function feed(mixed $principal, int $since): ApiResponse
    {
        return $this->allowed($principal, 'feed', ['ref' => null, 'address' => null], fn () => new ApiResponse(200, $this->feed->page(max(0, $since))));
    }

    /** POST /changes/{ref}/owner/approve-initiation {note?} */
    public function approveInitiation(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);

        return $this->ownerAction($principal, $ref, $body, $proof, OwnerAction::APPROVE_INITIATION, [], ['note'], fn (Change $c, array $b, string $owner) => $this->ledger->approveInitiation($c, $owner, is_string($b['note'] ?? null) ? $b['note'] : null));
    }

    /** POST /changes/{ref}/owner/reject-initiation {reason} */
    public function rejectInitiation(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);

        return $this->ownerAction($principal, $ref, $body, $proof, OwnerAction::REJECT_INITIATION, ['reason'], [], fn (Change $c, array $b, string $owner) => $this->ledger->rejectInitiation($c, $owner, (string) $b['reason']));
    }

    /** POST /changes/{ref}/owner/override {decision, reason, acknowledge[]} */
    public function override(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);
        if (is_array($body) && (! in_array($body['decision'] ?? null, ['approve', 'reject'], true) || ! is_array($body['acknowledge'] ?? []))) {
            $body = null;
        }

        return $this->ownerAction($principal, $ref, $body, $proof, OwnerAction::OVERRIDE, ['decision', 'reason'], ['acknowledge'], fn (Change $c, array $b, string $owner) => $this->ledger->override($c, $owner, $b['decision'], (string) $b['reason'], array_values(array_filter((array) ($b['acknowledge'] ?? []), 'is_int'))));
    }

    /** POST /changes/{ref}/owner/revoke {reason} */
    public function revoke(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);

        return $this->ownerAction($principal, $ref, $body, $proof, OwnerAction::REVOKE, ['reason'], [], fn (Change $c, array $b, string $owner) => $this->ledger->revoke($c, $owner, (string) $b['reason']));
    }

    /** PUT /changes/{ref}/silence-policy {address, policy|null} */
    public function changeSilencePolicy(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);

        return $this->ownerAction($principal, $ref, $body, $proof, OwnerAction::CHANGE_SILENCE_POLICY, ['address', 'policy'], [], fn (Change $c, array $b, string $owner) => $this->ledger->setChangeSilencePolicy($c, (string) $b['address'], is_string($b['policy']) ? $b['policy'] : null, $owner));
    }

    /** PUT /consumers/{address}/silence-policy {policy} */
    public function consumerSilencePolicy(mixed $principal, string $address, ?array $body): ApiResponse
    {
        [$body, $proof] = self::takeStepUp($body);
        if (($refused = $this->principal($principal)) !== null) {
            return $refused;
        }
        if (! $this->authorizer->allows($principal, 'owner', ['ref' => null, 'address' => $address])) {
            return self::error(403, 'forbidden');
        }
        if (! self::closed($body, ['policy']) || ! is_string($body['policy'])) {
            return self::error(422, 'invalid_request');
        }

        return $this->guard(function () use ($principal, $address, $body, $proof): ApiResponse {
            $owner = $this->actor($principal);
            $consumer = $this->stepUpGate($principal, null, $owner, new OwnerAction(OwnerAction::CONSUMER_SILENCE_POLICY, null, $address, $body), $proof, fn () => $this->ledger->setSilencePolicy($address, $body['policy'], $owner));

            return $consumer instanceof ApiResponse ? $consumer : new ApiResponse(200, ['consumer' => Presenter::consumer($consumer)]);
        });
    }

    // ------------------------------------------------------------------

    private function ownerAction(mixed $principal, string $ref, ?array $body, ?array $proof, string $name, array $required, array $optional, Closure $action): ApiResponse
    {
        return $this->withChange($principal, $ref, 'owner', function (Change $change) use ($principal, $body, $proof, $name, $required, $optional, $action): ApiResponse {
            if (! self::closed($body, $required, $optional)) {
                return self::error(422, 'invalid_request');
            }
            $owner = $this->actor($principal);
            $updated = $this->stepUpGate($principal, $change, $owner, new OwnerAction($name, $change->ref, null, $body), $proof, fn () => $action($change, $body, $owner));
            if ($updated instanceof ApiResponse) {
                return $updated;
            }

            return new ApiResponse(200, ['change' => $this->presenter->detail($updated)['change']]);
        });
    }

    /**
     * Splits the reserved `step_up` key off a body: [the body without it, the
     * proof or null]. A `step_up` that is neither an object nor null makes the
     * whole body invalid (null), which validation then answers with 422, so the
     * strict key sets of every endpoint stay as they were.
     *
     * @return array{0: ?array, 1: ?array}
     */
    private static function takeStepUp(?array $body): array
    {
        if ($body === null || ! array_key_exists('step_up', $body)) {
            return [$body, null];
        }
        $proof = $body['step_up'];
        unset($body['step_up']);
        if ($proof !== null && ! is_array($proof)) {
            return [null, null];
        }

        return [$body, $proof];
    }

    /**
     * The step-up gate, run after validation and the actor, before the ledger.
     * Returns an ApiResponse when the gate stops the action (428 no proof, 403
     * refused), else whatever `$run` returned. With a verified proof the
     * `owner.step_up` event and the action share one transaction
     * ({@see Ledger::withStepUp()}).
     */
    private function stepUpGate(mixed $principal, ?Change $change, string $owner, OwnerAction $action, ?array $proof, Closure $run): mixed
    {
        $stepUp = $this->stepUp ?? new NoStepUp;
        if (! $stepUp->required($principal, $action)) {
            return $run();
        }
        try {
            $digest = $action->digest();
        } catch (InvalidArgumentException) {
            // Params that have no canonical form (a float) cannot be bound to a proof.
            return self::error(422, 'invalid_request');
        }
        if ($proof === null) {
            $challenge = $stepUp->challenge($principal, $action);

            return self::error(428, 'step_up_required', [
                'step_up' => $challenge === [] ? new \stdClass : $challenge,
                'action' => [...$action->toArray(), 'params' => $action->params === [] ? new \stdClass : $action->params, 'digest' => $digest],
            ]);
        }
        try {
            $evidence = $stepUp->verify($principal, $action, $proof);
        } catch (StepUpRefused $e) {
            $this->ledger->stepUpRefused($change, $owner, $action, $e->reason, $e->evidence);

            return self::error(403, 'step_up_failed', ['reason' => $e->reason]);
        }

        return $this->ledger->withStepUp($change, $owner, $action, $evidence, $run);
    }

    private function withChange(mixed $principal, string $ref, string $ability, Closure $action): ApiResponse
    {
        if (($refused = $this->principal($principal)) !== null) {
            return $refused;
        }
        $change = $this->ledger->find($ref);
        if ($change === null) {
            // Asked before the hook only to give an honest 404; the hook still
            // decides everything a caller can read.
            return $this->authorizer->allows($principal, $ability, ['ref' => $ref, 'address' => null]) ? self::error(404, 'not_found') : self::error(403, 'forbidden');
        }
        if (! $this->authorizer->allows($principal, $ability, ['ref' => $change->ref, 'address' => null])) {
            return self::error(403, 'forbidden');
        }

        return $this->guard(fn () => $action($change));
    }

    private function allowed(mixed $principal, string $ability, array $subject, Closure $action): ApiResponse
    {
        if (($refused = $this->principal($principal)) !== null) {
            return $refused;
        }
        if (! $this->authorizer->allows($principal, $ability, $subject)) {
            return self::error(403, 'forbidden');
        }

        return $this->guard($action);
    }

    private function principal(mixed $principal): ?ApiResponse
    {
        if ($principal === null || ($principal instanceof Model && $principal->getKey() === null)) {
            return self::error(403, 'forbidden');
        }

        return null;
    }

    private function guard(Closure $action): ApiResponse
    {
        try {
            return $action();
        } catch (Refused $e) {
            return match ($e->reason) {
                'not_found' => self::error(404, 'not_found'),
                'invalid_state' => self::error(409, 'invalid_state'),
                // The host's resolveActorUsing() hook answered badly: a server
                // misconfiguration, not the caller's request.
                'invalid_actor' => self::error(500, 'invalid_actor', $e->context),
                default => self::error(422, $e->reason, $e->context),
            };
        }
    }

    public static function identity(mixed $principal): string
    {
        if ($principal instanceof Model && is_string($principal->getAttribute('email')) && $principal->getAttribute('email') !== '') {
            return $principal->getAttribute('email');
        }
        if ($principal instanceof Authenticatable) {
            return (string) $principal->getAuthIdentifier();
        }

        return $principal instanceof Model ? (string) $principal->getKey() : 'unknown';
    }

    private static function closed(?array $body, array $required, array $optional = []): bool
    {
        return $body !== null && array_diff($required, array_keys($body)) === []
            && array_diff(array_keys($body), [...$required, ...$optional]) === [];
    }

    public static function error(int $status, string $code, array $extra = []): ApiResponse
    {
        return new ApiResponse($status, ['error' => ['code' => $code, ...$extra]]);
    }
}
