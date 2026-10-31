<?php

declare(strict_types=1);

namespace ChangeCouncil\Notify;

/**
 * One owner-relevant transition, as refs, states, codes and counts ONLY. It
 * carries no free text (no title, note, reason or reply), so no notifier can
 * leak one: the same rule as {@see \ChangeCouncil\Feed\Feed}.
 *
 * Events: initiation_approved, initiation_rejected, override_approve,
 * override_reject, revoked, round1_closed / round2_closed, escalated.
 */
final class OwnerEvent
{
    public const INITIATION_APPROVED = 'initiation_approved';

    public const INITIATION_REJECTED = 'initiation_rejected';

    public const OVERRIDE_APPROVE = 'override_approve';

    public const OVERRIDE_REJECT = 'override_reject';

    public const REVOKED = 'revoked';

    public const ESCALATED = 'escalated';

    /**
     * @param  string|null  $code  a machine code (a tally reason such as `silence`), never prose
     * @param  array<string, int>  $counts  a closed round's tally counts
     */
    public function __construct(
        public readonly string $event,
        public readonly string $ref,
        public readonly ?string $changeId,
        public readonly string $state,
        public readonly ?string $fromState = null,
        public readonly ?int $round = null,
        public readonly ?string $code = null,
        public readonly array $counts = [],
    ) {}

    public static function roundClosed(int $round): string
    {
        return 'round'.$round.'_closed';
    }

    public function dedupeKey(): string
    {
        return 'council:'.$this->ref.':'.$this->event;
    }

    /** "initiation_approved" -> "initiation approved" */
    public function label(): string
    {
        return str_replace('_', ' ', $this->event);
    }
}
