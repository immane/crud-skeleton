<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Repository\OrderStoreLifecycleRepository;
use Doctrine\ORM\EntityManagerInterface;

class OrderStoreLifecycleService
{
    public function __construct(
        private readonly OrderStoreLifecycleRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $tradeOrderUuid): ?OrderStoreLifecycle
    {
        return $this->repository->findOneByTradeOrderUuid($tradeOrderUuid);
    }

    public function getOrCreate(string $tradeOrderUuid, string $storeUuid, ?string $storeOrderUuid = null): OrderStoreLifecycle
    {
        $existing = $this->repository->findOneByTradeOrderUuid($tradeOrderUuid);
        if ($existing !== null) {
            return $existing;
        }

        $lifecycle = new OrderStoreLifecycle($tradeOrderUuid, $storeUuid, $storeOrderUuid);
        $this->entityManager->persist($lifecycle);

        return $lifecycle;
    }

    public function markAccepted(string $tradeOrderUuid, string $storeUuid, ?string $storeOrderUuid = null): OrderStoreLifecycle
    {
        $lifecycle = $this->getOrCreate($tradeOrderUuid, $storeUuid, $storeOrderUuid);
        if ($lifecycle->getAcceptanceStatus() === OrderStoreLifecycle::ACCEPTANCE_ACCEPTED) {
            if ($storeOrderUuid !== null && $lifecycle->getStoreOrderUuid() === null) {
                $lifecycle->setStoreOrderUuid($storeOrderUuid);
            }
            return $lifecycle;
        }
        // If already rejected, keep rejected (first terminal wins); out-of-order accepted after rejected is ignored.
        if ($lifecycle->getAcceptanceStatus() === OrderStoreLifecycle::ACCEPTANCE_REJECTED) {
            return $lifecycle;
        }
        $lifecycle->markAccepted($storeOrderUuid);

        return $lifecycle;
    }

    public function markRejected(string $tradeOrderUuid, string $storeUuid, ?string $storeOrderUuid, ?string $code, ?string $reason): OrderStoreLifecycle
    {
        $lifecycle = $this->getOrCreate($tradeOrderUuid, $storeUuid, $storeOrderUuid);
        if ($lifecycle->getAcceptanceStatus() === OrderStoreLifecycle::ACCEPTANCE_REJECTED) {
            return $lifecycle;
        }
        // If already accepted, keep accepted; rejection after acceptance is ignored.
        if ($lifecycle->getAcceptanceStatus() === OrderStoreLifecycle::ACCEPTANCE_ACCEPTED) {
            return $lifecycle;
        }
        $lifecycle->markRejected($storeOrderUuid, $code, $reason);

        return $lifecycle;
    }

    public function markFulfilled(string $tradeOrderUuid, string $storeUuid, ?string $storeOrderUuid = null, bool $requiresVerification = false): OrderStoreLifecycle
    {
        $lifecycle = $this->getOrCreate($tradeOrderUuid, $storeUuid, $storeOrderUuid);
        if ($lifecycle->getFulfillmentStatus() === OrderStoreLifecycle::FULFILLMENT_FULFILLED) {
            return $lifecycle;
        }
        $lifecycle->markFulfilled($storeOrderUuid, $requiresVerification);

        return $lifecycle;
    }

    public function markVerified(string $tradeOrderUuid, string $storeUuid, ?string $storeOrderUuid = null): OrderStoreLifecycle
    {
        $lifecycle = $this->getOrCreate($tradeOrderUuid, $storeUuid, $storeOrderUuid);
        if ($lifecycle->getVerificationStatus() === OrderStoreLifecycle::VERIFICATION_VERIFIED) {
            return $lifecycle;
        }
        $lifecycle->markVerified($storeOrderUuid);

        return $lifecycle;
    }
}
