<?php

declare(strict_types=1);

namespace ChangeCouncil\Notify;

use Closure;

/** A host closure as a notifier: `Council::notifyUsing(fn (OwnerEvent $e) => ...)`. */
final class CallbackNotifier implements OwnerEventNotifier
{
    /** @param Closure(OwnerEvent): mixed $callback */
    public function __construct(private readonly Closure $callback) {}

    public function notify(OwnerEvent $event): void
    {
        ($this->callback)($event);
    }
}
