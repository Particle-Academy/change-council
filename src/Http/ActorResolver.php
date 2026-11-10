<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

use ChangeCouncil\Ledger\Refused;
use Closure;

/**
 * Who an owner action is recorded as (CC-fancy-0021). With no hook it is the
 * principal's email, else its key ({@see Api::identity()}); the Ledger stores
 * it as `owner:<id>`. A host registers a hook with
 * `Council::resolveActorUsing()` when its owners are better named another way.
 *
 * The hook's answer is validated and a bad one is refused with
 * `invalid_actor`: never a silent fallback to the email, because the audit
 * log is append-only and would keep a name the host did not ask for.
 */
final class ActorResolver
{
    public const MAX_LENGTH = 191;

    /** @param (Closure(mixed): mixed)|null $hook */
    public function __construct(private readonly ?Closure $hook = null) {}

    public function resolve(mixed $principal): string
    {
        if ($this->hook === null) {
            return Api::identity($principal);
        }

        $actor = ($this->hook)($principal);
        if (! is_string($actor)) {
            throw self::refuse('returned '.get_debug_type($actor).', expected a string');
        }
        if (trim($actor) === '') {
            throw self::refuse('returned an empty string');
        }
        if (preg_match('//u', $actor) !== 1) {
            throw self::refuse('returned invalid UTF-8');
        }
        if (preg_match_all('/./su', $actor) > self::MAX_LENGTH) {
            throw self::refuse('returned more than '.self::MAX_LENGTH.' characters');
        }
        // C0 (including newlines and tabs), DEL and C1 controls.
        if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $actor) === 1) {
            throw self::refuse('returned a control character or a newline');
        }

        return $actor;
    }

    private static function refuse(string $why): Refused
    {
        $detail = "Council::resolveActorUsing() hook {$why}; the owner action was not recorded";

        return new Refused('invalid_actor', $detail, ['detail' => $detail]);
    }
}
