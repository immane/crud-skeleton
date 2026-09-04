<?php

declare(strict_types=1);

namespace App\Store\MessageHandler;

use App\Store\Entity\StoreConsumedEvent;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreOutboxServiceInterface;
use App\Trade\Message\TradeOrderCreatedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TradeOrderCreatedHandler
{
    public function __construct(
        private StoreRepository $storeRepository,
        private StoreConsumedEventRepository $consumedEventRepository,
        private StoreTradeOrderCancellationRepository $cancellationRepository,
        private StoreOrderServiceInterface $storeOrderService,
        private StoreOutboxServiceInterface $outboxService,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(bool:INVENTORY_ENABLED)%')]
        private bool $inventoryEnabled = false,
    ) {
    }

    public function __invoke(TradeOrderCreatedMessage $message): void
    {
        $eventId = $message->envelope['eventId'] ?? null;
        $payload = $message->envelope['payload'] ?? null;
        if (!is_string($eventId) || !is_array($payload)) {
            throw new \InvalidArgumentException('Invalid trade.order.created.v1 envelope.');
        }
        if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
            return;
        }

        $storeSnapshot = $payload['store'] ?? null;
        $storeUuid = is_array($storeSnapshot) ? ($storeSnapshot['uuid'] ?? null) : null;
        if (!is_string($storeUuid)) {
            throw new \InvalidArgumentException('Trade order event does not include a store UUID.');
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $message, $payload, $storeUuid): void {
            if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
                return;
            }

            $encoded = json_encode($message->envelope, JSON_THROW_ON_ERROR);
            $this->entityManager->persist(new StoreConsumedEvent(
                $eventId,
                'trade.order.created.v1',
                (string) ($payload['orderUuid'] ?? ''),
                hash('sha256', $encoded),
            ));

            $cancellation = $this->cancellationRepository->findOneByTradeOrderUuid((string) ($payload['orderUuid'] ?? ''));
            if ($cancellation !== null && $cancellation->getStoreUuid() !== $storeUuid) {
                throw new \LogicException('Trade order cancellation conflicts with the Store order snapshot.');
            }

            $orderUuid = $payload['orderUuid'] ?? null;
            if (!is_string($orderUuid) || $orderUuid === '') {
                throw new \InvalidArgumentException('Trade order event does not include an order UUID.');
            }

            $store = $this->storeRepository->findOneByUuid($storeUuid);
            if ($store === null || !$store->isActive()) {
                throw new \RuntimeException('Store is not available.');
            }

            $storeOrder = $this->storeOrderService->createFromTradeOrderSnapshot($store, $payload);
            if ($cancellation !== null) {
                $storeOrder->cancel();
                return;
            }
            if ($storeOrder->getOperationalStatus() !== \App\Store\Entity\StoreOrder::STATUS_PENDING_VALIDATION) {
                return;
            }

            if (!$this->inventoryEnabled) {
                $this->storeOrderService->accept($storeOrder);
                return;
            }

            $reservationId = \App\Core\Utils\UUID::v4();
            $storeOrder->awaitInventory($reservationId);
            $this->outboxService->record('inventory.reservation.requested.v1', 'inventory_reservation', $reservationId, [
                'reservationId' => $reservationId,
                'storeUuid' => $storeOrder->getStore()->getUuid(),
                'tradeOrderUuid' => $storeOrder->getTradeOrderUuid(),
                'storeOrderUuid' => $storeOrder->getUuid(),
                'items' => $this->inventoryItems($payload),
                'expiresAt' => (new \DateTimeImmutable('+30 minutes'))->format(DATE_ATOM),
                'requestedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{lineId: string, catalogReference: string, quantity: string}>
     */
    private function inventoryItems(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Trade order event does not include inventory items.');
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !is_string($item['lineId'] ?? null)
                || !is_string($item['catalogReference'] ?? null)
                || !is_int($item['quantity'] ?? null)
                || $item['quantity'] <= 0) {
                throw new \InvalidArgumentException('Trade order event includes an invalid inventory item.');
            }
            $result[] = [
                'lineId' => $item['lineId'],
                'catalogReference' => $item['catalogReference'],
                'quantity' => sprintf('%d.000000', $item['quantity']),
            ];
        }

        return $result;
    }
}
