<?php

declare(strict_types=1);

namespace App\Store\EventListener;

use App\Store\DTO\StoreSettings;
use App\Store\Repository\StoreRepository;
use App\Trade\Entity\Order;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class StoreOrderWorkflowGuardListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly StoreRepository $storeRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.order.guard.submit' => 'onGuard',
            'workflow.order.guard.store_submit' => 'onGuard',
            'workflow.order.guard.complete' => 'onGuard',
            'workflow.order.guard.request_verification' => 'onGuard',
            'workflow.order.guard.store_verify' => 'onGuard',
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
        $settings = $this->resolveSettings($subject);
        $hasStore = $this->hasStore($subject);

        match ($transition) {
            'submit' => $this->guardSubmit($event, $settings, $hasStore),
            'store_submit' => $this->guardStoreSubmit($event, $settings, $hasStore),
            'complete' => $this->guardComplete($event, $settings, $hasStore),
            'request_verification' => $this->guardRequestVerification($event, $settings, $hasStore),
            'store_verify' => $this->guardStoreVerify($event, $settings, $hasStore),
            default => null,
        };
    }

    /**
     * @param GuardEvent<Order> $event
     */
    private function guardSubmit(GuardEvent $event, StoreSettings $settings, bool $hasStore): void
    {
        // When requireAcceptance is true and order has store, submit must be blocked (must go store_submit)
        if ($hasStore && $settings->requireAcceptance) {
            $event->setBlocked(true, 'Store acceptance required: use store_submit.');
        }
    }

    /**
     * @param GuardEvent<Order> $event
     */
    private function guardStoreSubmit(GuardEvent $event, StoreSettings $settings, bool $hasStore): void
    {
        // store_submit only guarded when order has store context
        // Plain orders (no _store metadata) keep workflow-layer permissive for tests/legacy
        if (!$hasStore) {
            return;
        }
        if (!$settings->requireAcceptance) {
            $event->setBlocked(true, 'Store acceptance is disabled for this store.');
        }
    }

    /**
     * @param GuardEvent<Order> $event
     */
    private function guardComplete(GuardEvent $event, StoreSettings $settings, bool $hasStore): void
    {
        // When verification required, direct complete must be blocked
        if ($hasStore && $settings->requireVerification) {
            $event->setBlocked(true, 'Store verification required: fulfill -> request_verification -> store_verify.');
        }
    }

    /**
     * @param GuardEvent<Order> $event
     */
    private function guardRequestVerification(GuardEvent $event, StoreSettings $settings, bool $hasStore): void
    {
        if (!$hasStore) {
            $event->setBlocked(true, 'No store context.');
            return;
        }
        if (!$settings->requireVerification) {
            $event->setBlocked(true, 'Store verification is disabled for this store.');
        }
    }

    /**
     * @param GuardEvent<Order> $event
     */
    private function guardStoreVerify(GuardEvent $event, StoreSettings $settings, bool $hasStore): void
    {
        if (!$hasStore) {
            $event->setBlocked(true, 'No store context.');
            return;
        }
        if (!$settings->requireVerification) {
            $event->setBlocked(true, 'Store verification is disabled for this store.');
        }
    }

    private function hasStore(Order $order): bool
    {
        $metadata = $order->getMetadata();
        $store = is_array($metadata) ? ($metadata['_store'] ?? null) : null;
        return is_array($store) && is_string($store['uuid'] ?? null) && $store['uuid'] !== '';
    }

    private function resolveSettings(Order $order): StoreSettings
    {
        $metadata = $order->getMetadata();
        $store = is_array($metadata) ? ($metadata['_store'] ?? null) : null;
        if (!is_array($store) || !is_string($store['uuid'] ?? null)) {
            return new StoreSettings(false, false);
        }

        $storeEntity = $this->storeRepository->findOneBy(['uuid' => $store['uuid']]);
        if ($storeEntity === null) {
            // Store deleted/invalid: treat as no requirement to avoid blocking legacy orders
            return new StoreSettings(false, false);
        }

        return StoreSettings::from($storeEntity->getSettings());
    }
}
