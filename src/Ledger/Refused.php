<?php

declare(strict_types=1);

namespace ChangeCouncil\Ledger;

use RuntimeException;

/** An expected refusal with a stable code; the API maps it to a 4xx. */
final class Refused extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $reason, string $message = '', public readonly array $context = [])
    {
        parent::__construct($reason.($message !== '' ? ': '.$message : ''));
    }
}
