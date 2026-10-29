<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Payment\Repository\InvoiceRefundAttemptRepository;
use App\Trade\Entity\Order;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final readonly class OrderRefundGuardListener implements EventSubscriberInterface
{
    public function __construct(private InvoiceRefundAttemptRepository $attempts)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.order.guard.fulfill' => 'onFulfill'];
    }

    /** @param GuardEvent<Order> $event */
    public function onFulfill(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof Order && $this->attempts->hasUnresolvedForSource('trade_order', $order->getUuid())) {
            $event->setBlocked(true, 'An unresolved refund must be completed or reconciled before fulfillment.');
        }
    }
}
