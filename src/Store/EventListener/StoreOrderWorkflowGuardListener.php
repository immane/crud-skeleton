<?php

declare(strict_types=1);

namespace App\Store\EventListener;

use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Repository\OrderStoreLifecycleRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class StoreOrderWorkflowGuardListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?OrderStoreLifecycleRepository $lifecycleRepository = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.order.guard.confirm' => 'onGuard',
            'workflow.order.guard.complete' => 'onGuard',
        ];
    }

    /**
     * @param GuardEvent<Order> $event
     */
    public function onGuard(GuardEvent $event): void
    {
        $subject = $event->getSubject();
        if (!$subject instanceof Order) {
            return;
        }

        $transition = $event->getTransition()->getName();
        $hasStore = $this->hasStore($subject);

        match ($transition) {
            'confirm' => $this->guardConfirm($event, $hasStore),
            'complete' => $this->guardComplete($event, $hasStore),
            default => null,
        };
    }

    /**
     * @param GuardEvent<Order> $event
     */
    private function guardConfirm(GuardEvent $event, bool $hasStore): void
    {
        if (!$hasStore) {
            return;
        }
        if ($this->lifecycleRepository === null) {
            return;
        }
        $order = $event->getSubject();
        \assert($order instanceof Order);
        $lifecycle = $this->lifecycleRepository->findOneByTradeOrderUuid($order->getUuid());
        if ($lifecycle === null || $lifecycle->getAcceptanceStatus() !== OrderStoreLifecycle::ACCEPTANCE_ACCEPTED) {
            $event->setBlocked(true, 'Store acceptance required before confirm.');
        }
    }

    /**
     * @param GuardEvent<Order> $event
     */
    private function guardComplete(GuardEvent $event, bool $hasStore): void
    {
        if (!$hasStore) {
            return;
        }
        if ($this->lifecycleRepository === null) {
            return;
        }
        $order = $event->getSubject();
        \assert($order instanceof Order);
        $lifecycle = $this->lifecycleRepository->findOneByTradeOrderUuid($order->getUuid());
        if ($lifecycle !== null && $lifecycle->getVerificationStatus() === OrderStoreLifecycle::VERIFICATION_PENDING) {
            $event->setBlocked(true, 'Store verification required before complete.');
        }
    }

    private function hasStore(Order $order): bool
    {
        $metadata = $order->getMetadata();
        $store = is_array($metadata) ? ($metadata['_store'] ?? null) : null;
        return is_array($store) && is_string($store['uuid'] ?? null) && $store['uuid'] !== '';
    }
}
