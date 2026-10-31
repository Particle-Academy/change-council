<?php

declare(strict_types=1);

namespace ChangeCouncil\Notify;

/** The default: owner events go nowhere. */
final class NullNotifier implements OwnerEventNotifier
{
    public function notify(OwnerEvent $event): void {}
}
