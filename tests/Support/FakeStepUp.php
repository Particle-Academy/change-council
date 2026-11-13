<?php

declare(strict_types=1);

namespace ChangeCouncil\Tests\Support;

use ChangeCouncil\StepUp\OwnerAction;
use ChangeCouncil\StepUp\StepUp;
use ChangeCouncil\StepUp\StepUpRefused;

/**
 * A step-up hook for the suite. The proof it accepts is
 * `{"token": "good", "digest": <the action's digest>}`, so a proof made for
 * one action cannot be presented for another: the fake binds the proof to the
 * digest the way a real ceremony binds its challenge.
 */
final class FakeStepUp implements StepUp
{
    /** @var list<array{0: string, 1: OwnerAction, 2?: array}> */
    public array $calls = [];

    public function __construct(public bool $require = true) {}

    public function required(mixed $principal, OwnerAction $action): bool
    {
        $this->calls[] = ['required', $action];

        return $this->require;
    }

    public function challenge(mixed $principal, OwnerAction $action): array
    {
        $this->calls[] = ['challenge', $action];

        return ['kind' => 'fake', 'challenge_id' => 'ch-1', 'digest' => $action->digest()];
    }

    public function verify(mixed $principal, OwnerAction $action, array $proof): array
    {
        $this->calls[] = ['verify', $action, $proof];
        if (($proof['token'] ?? null) !== 'good') {
            throw new StepUpRefused('bad_token', ['presented' => is_string($proof['token'] ?? null) ? 'string' : 'none']);
        }
        if (($proof['digest'] ?? null) !== $action->digest()) {
            throw new StepUpRefused('action_mismatch', ['expected' => $action->digest()]);
        }

        return ['method' => 'fake', 'credential' => 'cred-1', 'digest' => $action->digest()];
    }

    /** @return list<string> the action names verify() was asked about */
    public function verified(): array
    {
        return array_values(array_map(fn (array $c): string => $c[1]->action, array_filter($this->calls, fn (array $c): bool => $c[0] === 'verify')));
    }
}
