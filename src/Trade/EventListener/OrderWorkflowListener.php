<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Trade\Entity\Order;
use App\Trade\Event\OrderCancelledEvent;
use App\Trade\Event\OrderCompletedEvent;
use App\Trade\Event\OrderFulfilledEvent;
use App\Trade\Event\OrderPaidEvent;
use App\Trade\Event\OrderRefundedEvent;
use App\Trade\Service\TradeOutboxServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Subscribes to workflow.order.transition events from the Symfony Workflow,
 * sets entity timestamps, and broadcasts domain events for cross-module consumption.
 *
 * After each meaningful transition, a domain event is dispatched:
 *   pay → OrderPaidEvent
 *   fulfill → OrderFulfilledEvent
 *   * → completed (complete via Trade or store_verify via Store) → OrderCompletedEvent
 *   cancel → OrderCancelledEvent
 *   refund → OrderRefundedEvent
 *
 * Completed is status-driven so Trade does not need to know Store transition names.
 * Other modules subscribe to these events without depending on Trade internals.
 *
 * @see config/packages/workflow.yaml for the state machine definition
 */
class OrderWorkflowListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?TradeOutboxServiceInterface $outboxService = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.order.transition' => 'onTransition',
        ];
    }

    /**
     * @param TransitionEvent<object> $event
     */
    public function onTransition(TransitionEvent $event): void
    {
        $order = $event->getSubject();
        \assert($order instanceof Order);
        $transition = $event->getTransition();
        \assert($transition !== null);
        $transitionName = $transition->getName();

        $this->logger->info(sprintf(
            'Order #%d transition: %s',
            $order->getId() ?? 0,
            $transitionName,
        ));

        // Set timestamps - Trade owns pay/fulfill/cancel/refund/complete; store_verify lands in completed via Store
        switch ($transitionName) {
            case 'cancel':
                $order->setCancelledAt(new \DateTimeImmutable());
                break;
            case 'pay':
                if ($order->getPaidAt() === null) {
                    $order->setPaidAt(new \DateTimeImmutable());
                }
                break;
            case 'fulfill':
                if ($order->getFulfilledAt() === null) {
                    $order->setFulfilledAt(new \DateTimeImmutable());
                }
                break;
            case 'complete':
                $order->setCompletedAt(new \DateTimeImmutable());
                break;
            case 'refund':
                if ($order->getRefundedAt() === null) {
                    $order->setRefundedAt(new \DateTimeImmutable());
                }
                break;
        }
        // Store-driven store_verify also lands in completed but Trade should not know the name; handle generically
        if ($order->getStatus() === Order::STATUS_COMPLETED && $transitionName !== 'complete') {
            $order->setCompletedAt(new \DateTimeImmutable());
        }

        // Dispatch domain events for cross-module subscribers
        $domainEvent = match ($transitionName) {
            'cancel' => new OrderCancelledEvent($order),
            'pay' => new OrderPaidEvent($order),
            'fulfill' => new OrderFulfilledEvent($order),
            'complete' => new OrderCompletedEvent($order),
            'refund' => new OrderRefundedEvent($order),
            default => $order->getStatus() === Order::STATUS_COMPLETED ? new OrderCompletedEvent($order) : null,
        };

        if ($domainEvent !== null) {
            $this->dispatcher->dispatch($domainEvent);
        }

        if ($transitionName === 'cancel' && $this->outboxService !== null) {
            $metadata = $order->getMetadata();
            $store = is_array($metadata) ? ($metadata['_store'] ?? null) : null;
            if (is_array($store) && is_string($store['uuid'] ?? null)) {
                $this->outboxService->record('trade.order.cancelled.v1', 'trade_order', $order->getUuid(), [
                    'orderUuid' => $order->getUuid(),
                    'storeUuid' => $store['uuid'],
                    'cancelledAt' => $order->getCancelledAt()?->format(DATE_ATOM),
                ]);
            }
        }
    }
}
