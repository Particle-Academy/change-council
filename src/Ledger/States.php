<?php

declare(strict_types=1);

namespace ChangeCouncil\Ledger;

/**
 * The Change lifecycle (design 6.1, as amended by the 2026-10-27 owner rulings):
 *
 *   propose -> awaiting_owner     (non-whitelisted initiation, agent or GitHub issue: HITL)
 *           -> round1_open        (whitelisted initiation: agent, or GitHub issue author)
 *   awaiting_owner -> round1_open | rejected                   (owner)
 *   round1_open -> approved                 (tally: no blocking conflict, no escalating silence)
 *               -> awaiting_revision        (a blocker/major conflict)
 *               -> escalated                (silence/unreachable under `escalate`, or a stale census)
 *   awaiting_revision -> round2_open        (the initiator writes a revised approach)
 *   round2_open -> approved | escalated     (tally never rejects; the owner does)
 *   escalated -> approved | rejected        (owner)
 *   any non-final -> withdrawn              (initiator)
 *   any -> approved | rejected              (owner override, reason required)
 *   approved -> revoked                     (owner)
 */
final class States
{
    public const AWAITING_OWNER = 'awaiting_owner';

    public const ROUND1_OPEN = 'round1_open';

    public const AWAITING_REVISION = 'awaiting_revision';

    public const ROUND2_OPEN = 'round2_open';

    public const ESCALATED = 'escalated';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const REVOKED = 'revoked';

    public const ALL = [self::AWAITING_OWNER, self::ROUND1_OPEN, self::AWAITING_REVISION, self::ROUND2_OPEN, self::ESCALATED, self::APPROVED, self::REJECTED, self::WITHDRAWN, self::REVOKED];

    public const OPEN_ROUND = [self::ROUND1_OPEN, self::ROUND2_OPEN];

    /** Nothing moves out of these except an owner override or revoke. */
    public const FINAL = [self::APPROVED, self::REJECTED, self::WITHDRAWN, self::REVOKED];

    public const SOURCES = ['agent_finding', 'agent_request', 'github_issue'];

    public static function isFinal(string $state): bool
    {
        return in_array($state, self::FINAL, true);
    }
}
