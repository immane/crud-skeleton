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

            $store = $this->storeRepository->findOneByUuid($storeUuid);
            if ($store === null || !$store->isActive()) {
                $this->recordRejected($payload, $storeUuid, 'STORE_UNAVAILABLE', 'Store is not available.');
                return;
            }

            $policy = $this->storePolicy($payload);
            $storeOrder = $this->storeOrderService->createFromTradeOrderSnapshot($store, $payload);
            if ($cancellation !== null) {
                $this->storeOrderService->cancel($storeOrder);
                return;
            }
            if ($storeOrder->getOperationalStatus() !== \App\Store\Entity\StoreOrder::STATUS_PENDING_VALIDATION) {
                return;
            }

            if (!$this->inventoryEnabled || !$policy['requireInventory']) {
                if ($policy['requireAcceptance']) {
                    // Manual acceptance: leave pending for staff accept/reject via API.
                    return;
                }
                $this->storeOrderService->accept($storeOrder);
                return;
            }

            // Inventory-gated acceptance: await the reservation outcome. When staff
            // acceptance is also required, the confirmed reservation leaves the order
            // awaiting manual accept instead of auto-accepting.

            $reservationId = \App\Core\Utils\UUID::v4();
            $this->storeOrderService->awaitInventory($storeOrder, $reservationId);
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
     * Acceptance/inventory policy frozen in the event payload (StoreContext snapshot).
     *
     * @param array<string, mixed> $payload
     * @return array{requireAcceptance: bool, requireInventory: bool}
     */
    private function storePolicy(array $payload): array
    {
        $storeSnapshot = $payload['store'] ?? null;
        $policy = is_array($storeSnapshot) ? $storeSnapshot : [];
        foreach (['requireAcceptance', 'requireInventory'] as $key) {
            $value = $policy[$key] ?? false;
            if (!is_bool($value)) {
                throw new \InvalidArgumentException(sprintf('Trade order store %s must be a boolean.', $key));
            }
            $policy[$key] = $value;
        }

        return ['requireAcceptance' => $policy['requireAcceptance'], 'requireInventory' => $policy['requireInventory']];
    }

    /** @param array<string, mixed> $payload */
    private function recordRejected(array $payload, string $storeUuid, string $code, string $reason): void    {
        $orderUuid = $payload['orderUuid'] ?? null;
        if (!is_string($orderUuid) || $orderUuid === '') {
            throw new \InvalidArgumentException('Trade order event does not include an order UUID.');
        }

        $this->outboxService->record('store.order.rejected.v1', 'trade_order', $orderUuid, [
            'orderUuid' => $orderUuid,
            'storeOrderUuid' => null,
            'storeUuid' => $storeUuid,
            'reasonCode' => $code,
            'reason' => $reason,
            'rejectedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
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
