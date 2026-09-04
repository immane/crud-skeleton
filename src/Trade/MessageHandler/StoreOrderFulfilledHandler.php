<?php

declare(strict_types=1);

namespace App\Trade\MessageHandler;

use App\Store\DTO\StoreSettings;
use App\Store\Repository\StoreRepository;
use App\Trade\Entity\TradeConsumedEvent;
use App\Trade\Message\StoreOrderFulfilledMessage;
use App\Trade\Repository\TradeConsumedEventRepository;
use App\Trade\Service\OrderStoreLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class StoreOrderFulfilledHandler
{
    public function __construct(
        private readonly ?TradeConsumedEventRepository $consumedRepository = null,
        private readonly ?OrderStoreLifecycleService $lifecycleService = null,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?StoreRepository $storeRepository = null,
    ) {
    }

    public function __invoke(StoreOrderFulfilledMessage $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        $orderUuid = is_array($payload) ? ($payload['orderUuid'] ?? null) : null;
        $storeUuid = is_array($payload) ? ($payload['storeUuid'] ?? null) : null;
        if (!is_string($orderUuid) || !is_string($storeUuid)) {
            throw new \InvalidArgumentException('Invalid store.order.fulfilled.v1 envelope.');
        }

        $eventId = $message->envelope['eventId'] ?? null;
        if (is_string($eventId) && $this->consumedRepository !== null && $this->entityManager !== null) {
            if ($this->consumedRepository->findOneByEventId($eventId) !== null) {
                return;
            }
            $this->entityManager->wrapInTransaction(function () use ($eventId, $message, $payload, $orderUuid, $storeUuid): void {
                if ($this->consumedRepository->findOneByEventId($eventId) !== null) {
                    return;
                }
                $this->entityManager->persist(new TradeConsumedEvent(
                    $eventId,
                    'store.order.fulfilled.v1',
                    $orderUuid,
                    hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR)),
                ));
                $this->markFulfilled($orderUuid, $storeUuid, $payload);
            });

            return;
        }

        $this->markFulfilled($orderUuid, $storeUuid, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function markFulfilled(string $orderUuid, string $storeUuid, array $payload): void
    {
        if ($this->lifecycleService === null) {
            return;
        }
        $storeOrderUuid = is_string($payload['storeOrderUuid'] ?? null) ? $payload['storeOrderUuid'] : null;
        $requiresVerification = false;
        if ($this->storeRepository !== null) {
            $store = $this->storeRepository->findOneBy(['uuid' => $storeUuid]);
            if ($store !== null) {
                $requiresVerification = StoreSettings::from($store->getSettings())->requireVerification;
            }
        }
        $this->lifecycleService->markFulfilled($orderUuid, $storeUuid, $storeOrderUuid, $requiresVerification);
    }
}
