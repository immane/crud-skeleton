<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Trade\Entity\Order;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class OrderCompletionGuardListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['workflow.order.guard.complete' => 'onGuardComplete'];
    }

    /** @param GuardEvent<Order> $event */
    public function onGuardComplete(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof Order) {
            return;
        }
        if (($order->getMetadata()['_completionMode'] ?? 'manual') !== 'store_verification') {
            return;
        }
        if (!$order->isCompletingFromStoreVerification()) {
            $event->setBlocked(true, 'Store verification is required before completing this order.');
        }
    }
}
