<?php

declare(strict_types=1);

namespace ChangeCouncil\Notify;

/**
 * Told about an owner-relevant transition AFTER its transaction commits (a
 * rollback never notifies). Bound to {@see NullNotifier} by default; a host
 * opts in with `Council::notifyUsing()`, `Council::notifyGenie()` or the
 * `council.notify.genie` switch.
 *
 * An implementation should record its own failures rather than throw. If it
 * throws anyway, the ledger swallows it and audits `notify.failed`: the owner
 * decision has already committed and must not fail because of a notice.
 */
interface OwnerEventNotifier
{
    public function notify(OwnerEvent $event): void;
}
