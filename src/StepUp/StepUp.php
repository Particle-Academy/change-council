<?php

declare(strict_types=1);

namespace ChangeCouncil\StepUp;

/**
 * A host's step-up check before an OWNER action runs (a passkey ceremony, a
 * TOTP, a second device: the council does not know or care). Bind one with
 * `Council::requireStepUpUsing()`; the default is {@see NoStepUp}.
 *
 * Flow, per owner request, after authorization and body validation:
 *
 * 1. `required()`. False: the action runs as it always did.
 * 2. No `step_up` in the body: `challenge()`, answered as HTTP 428
 *    `step_up_required`. Nothing is written.
 * 3. A `step_up` object in the body: `verify()`. A {@see StepUpRefused} is
 *    audited as `owner.step_up_refused` and answered 403 `step_up_failed`;
 *    the evidence returned is audited as `owner.step_up` in the same
 *    transaction as the action.
 *
 * Everything returned must be JSON-serialisable; evidence is hashed into the
 * audit chain with canonical JSON, which refuses floats.
 */
interface StepUp
{
    /** Does this principal need a step-up for this action? */
    public function required(mixed $principal, OwnerAction $action): bool;

    /**
     * Called only when required() is true and the request carried no proof.
     *
     * @return array<string, mixed> a host-defined payload for the client
     */
    public function challenge(mixed $principal, OwnerAction $action): array;

    /**
     * Verify the proof for exactly this action (bind it to {@see OwnerAction::digest()}).
     *
     * @param  array<mixed>  $proof  the request's `step_up` value
     * @return array<string, mixed> the evidence to record in the audit log
     *
     * @throws StepUpRefused to refuse
     */
    public function verify(mixed $principal, OwnerAction $action, array $proof): array;
}
