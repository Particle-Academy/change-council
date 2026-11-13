<?php

declare(strict_types=1);

namespace ChangeCouncil\StepUp;

use RuntimeException;

/**
 * A step-up proof the host refuses. `reason` is a short code
 * (`challenge_not_found`, `action_mismatch`, ...) returned to the client in a
 * 403 `step_up_failed`; `evidence` is recorded in the `owner.step_up_refused`
 * audit event only, never returned.
 */
final class StepUpRefused extends RuntimeException
{
    /** @param array<string, mixed> $evidence */
    public function __construct(public readonly string $reason, public readonly array $evidence = [], string $message = '')
    {
        parent::__construct($reason.($message !== '' ? ': '.$message : ''));
    }
}
