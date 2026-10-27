<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

use ChangeCouncil\Feed\Feed;
use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Ledger\Refused;
use ChangeCouncil\Ledger\States;
use ChangeCouncil\Models\Change;
use ChangeCouncil\Models\Consumer;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The transport contract without a framework (charlotte's shape): each method
 * takes the authenticated principal and the decoded body, and returns a status
 * plus JSON. Order of checks: principal present, Change found, then the
 * authorization hook for the ability. Owner actions record `owner:<identity>`,
 * where the identity is the principal's email when it has one, else its key.
 */
final class Api
{
    public function __construct(private readonly Ledger $ledger, private readonly Presenter $presenter, private readonly Feed $feed, private readonly Authorizer $authorizer) {}

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
        return $this->ownerAction($principal, $ref, $body, [], ['note'], fn (Change $c, array $b, string $owner) => $this->ledger->approveInitiation($c, $owner, is_string($b['note'] ?? null) ? $b['note'] : null));
    }

    /** POST /changes/{ref}/owner/reject-initiation {reason} */
    public function rejectInitiation(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        return $this->ownerAction($principal, $ref, $body, ['reason'], [], fn (Change $c, array $b, string $owner) => $this->ledger->rejectInitiation($c, $owner, (string) $b['reason']));
    }

    /** POST /changes/{ref}/owner/override {decision, reason, acknowledge[]} */
    public function override(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        if (is_array($body) && (! in_array($body['decision'] ?? null, ['approve', 'reject'], true) || ! is_array($body['acknowledge'] ?? []))) {
            $body = null;
        }

        return $this->ownerAction($principal, $ref, $body, ['decision', 'reason'], ['acknowledge'], fn (Change $c, array $b, string $owner) => $this->ledger->override($c, $owner, $b['decision'], (string) $b['reason'], array_values(array_filter((array) ($b['acknowledge'] ?? []), 'is_int'))));
    }

    /** POST /changes/{ref}/owner/revoke {reason} */
    public function revoke(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        return $this->ownerAction($principal, $ref, $body, ['reason'], [], fn (Change $c, array $b, string $owner) => $this->ledger->revoke($c, $owner, (string) $b['reason']));
    }

    /** PUT /changes/{ref}/silence-policy {address, policy|null} */
    public function changeSilencePolicy(mixed $principal, string $ref, ?array $body): ApiResponse
    {
        return $this->ownerAction($principal, $ref, $body, ['address', 'policy'], [], fn (Change $c, array $b, string $owner) => $this->ledger->setChangeSilencePolicy($c, (string) $b['address'], is_string($b['policy']) ? $b['policy'] : null, $owner));
    }

    /** PUT /consumers/{address}/silence-policy {policy} */
    public function consumerSilencePolicy(mixed $principal, string $address, ?array $body): ApiResponse
    {
        if (($refused = $this->principal($principal)) !== null) {
            return $refused;
        }
        if (! $this->authorizer->allows($principal, 'owner', ['ref' => null, 'address' => $address])) {
            return self::error(403, 'forbidden');
        }
        if (! self::closed($body, ['policy']) || ! is_string($body['policy'])) {
            return self::error(422, 'invalid_request');
        }

        return $this->guard(fn () => new ApiResponse(200, ['consumer' => Presenter::consumer($this->ledger->setSilencePolicy($address, $body['policy'], self::identity($principal)))]));
    }

    // ------------------------------------------------------------------

    private function ownerAction(mixed $principal, string $ref, ?array $body, array $required, array $optional, Closure $action): ApiResponse
    {
        return $this->withChange($principal, $ref, 'owner', function (Change $change) use ($principal, $body, $required, $optional, $action): ApiResponse {
            if (! self::closed($body, $required, $optional)) {
                return self::error(422, 'invalid_request');
            }
            $updated = $action($change, $body, self::identity($principal));

            return new ApiResponse(200, ['change' => $this->presenter->detail($updated)['change']]);
        });
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
