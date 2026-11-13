<?php

declare(strict_types=1);

namespace ChangeCouncil\StepUp;

use ChangeCouncil\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * One owner action, as a step-up proof is bound to it. `ref` is the Change's
 * ref for a change action, else null; `address` is the member's or consumer's
 * address for a member or consumer action, else null; `params` is the
 * validated request body without its `step_up` key.
 *
 * The digest covers all four, so a proof made for "approve CC-fancy-0001 with
 * note A" does not carry "approve it with note B", another Change, or another
 * member. A host's ceremony signs over {@see digest()}.
 */
final class OwnerAction
{
    public const APPROVE_INITIATION = 'approve-initiation';

    public const REJECT_INITIATION = 'reject-initiation';

    public const OVERRIDE = 'override';

    public const REVOKE = 'revoke';

    public const CHANGE_SILENCE_POLICY = 'change-silence-policy';

    public const CONSUMER_SILENCE_POLICY = 'consumer-silence-policy';

    public const MEMBER_WEIGHT = 'member-weight';

    public const MEMBER_PROJECT = 'member-project';

    public const MEMBER_REMOVE = 'member-remove';

    public const BLOCKING_THRESHOLD = 'blocking-threshold';

    /** Every owner endpoint of the API, in route order. */
    public const ALL = [
        self::APPROVE_INITIATION,
        self::REJECT_INITIATION,
        self::OVERRIDE,
        self::REVOKE,
        self::CHANGE_SILENCE_POLICY,
        self::CONSUMER_SILENCE_POLICY,
        self::MEMBER_WEIGHT,
        self::MEMBER_PROJECT,
        self::MEMBER_REMOVE,
        self::BLOCKING_THRESHOLD,
    ];

    /** @param array<string, mixed> $params */
    public function __construct(
        public readonly string $action,
        public readonly ?string $ref,
        public readonly ?string $address,
        public readonly array $params,
    ) {
        if (! in_array($action, self::ALL, true)) {
            throw new InvalidArgumentException("Unknown owner action '{$action}'; expected one of ".implode(', ', self::ALL));
        }
    }

    /** @return array{action: string, ref: ?string, address: ?string, params: array<string, mixed>} */
    public function toArray(): array
    {
        return ['action' => $this->action, 'ref' => $this->ref, 'address' => $this->address, 'params' => $this->params];
    }

    /**
     * The byte-exact form the digest is taken over ({@see CanonicalJson}).
     * Empty params encode as `{}`. A float anywhere in params is refused with
     * an InvalidArgumentException, as CanonicalJson refuses every float.
     */
    public function canonical(): string
    {
        $value = $this->toArray();
        if ($value['params'] === []) {
            $value['params'] = new \stdClass;
        }

        return CanonicalJson::encode($value);
    }

    /** `sha256:<hex>` of {@see canonical()}. */
    public function digest(): string
    {
        return 'sha256:'.hash('sha256', $this->canonical());
    }
}
