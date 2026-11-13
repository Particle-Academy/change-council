<?php

declare(strict_types=1);

namespace ChangeCouncil\StepUp;

use LogicException;

/** The default: no owner action needs a step-up, so nothing changes. */
final class NoStepUp implements StepUp
{
    public function required(mixed $principal, OwnerAction $action): bool
    {
        return false;
    }

    public function challenge(mixed $principal, OwnerAction $action): array
    {
        throw new LogicException('NoStepUp never requires a step-up, so it issues no challenge');
    }

    public function verify(mixed $principal, OwnerAction $action, array $proof): array
    {
        throw new LogicException('NoStepUp never requires a step-up, so it verifies nothing');
    }
}
