<?php

declare(strict_types=1);

namespace App\Store\MessageHandler;

use App\Store\Entity\StoreConsumedEvent;
use App\Store\Entity\StoreOrder;
use App\Store\Entity\StoreTradeOrderCancellation;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreOutboxServiceInterface;
use App\Trade\Message\TradeOrderCancelledMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TradeOrderCancelledHandler
{
    public function __construct(
        private StoreConsumedEventRepository $consumedEventRepository,
        private StoreOrderRepository $storeOrderRepository,
        private StoreTradeOrderCancellationRepository $cancellationRepository,
        private StoreOutboxServiceInterface $outboxService,
        private EntityManagerInterface $entityManager,
        private ?StoreOrderServiceInterface $storeOrderService = null,
    ) {
    }

    public function __invoke(TradeOrderCancelledMessage $message): void
    {
        $eventId = $message->envelope['eventId'] ?? null;
        $payload = $message->envelope['payload'] ?? null;
        if (($message->envelope['type'] ?? null) !== 'trade.order.cancelled'
            || ($message->envelope['version'] ?? null) !== 1
            || !is_string($eventId)
            || !is_array($payload)
            || !is_string($payload['orderUuid'] ?? null)
            || !is_string($payload['storeUuid'] ?? null)
            || !is_string($payload['cancelledAt'] ?? null)) {
            throw new \InvalidArgumentException('Invalid trade.order.cancelled.v1 envelope.');
        }
        $cancelledAt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $payload['cancelledAt']);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($cancelledAt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Invalid trade.order.cancelled.v1 envelope.');
        }
        if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($eventId, $payload, $message, $cancelledAt): void {
            if ($this->consumedEventRepository->findOneBy(['eventId' => $eventId]) !== null) {
                return;
            }
            $this->entityManager->persist(new StoreConsumedEvent(
                $eventId,
                'trade.order.cancelled.v1',
                $payload['orderUuid'],
                hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR)),
            ));

            $storeOrder = $this->storeOrderRepository->findOneByTradeOrderUuid($payload['orderUuid']);
            if ($storeOrder === null) {
                $cancellation = $this->cancellationRepository->findOneByTradeOrderUuid($payload['orderUuid']);
                if ($cancellation !== null) {
                    if ($cancellation->getStoreUuid() !== $payload['storeUuid']) {
                        throw new \LogicException('Trade order cancellation conflicts with the Store order snapshot.');
                    }
                    return;
                }
                $this->entityManager->persist(new StoreTradeOrderCancellation($payload['orderUuid'], $payload['storeUuid'], $cancelledAt));
                return;
            }
            if ($storeOrder->getStore()->getUuid() !== $payload['storeUuid']
                || in_array($storeOrder->getOperationalStatus(), [StoreOrder::STATUS_CANCELLED, StoreOrder::STATUS_REJECTED, StoreOrder::STATUS_FULFILLED], true)) {
                return;
            }
            if ($this->storeOrderService !== null) {
                $this->storeOrderService->cancel($storeOrder);
            } else {
                $storeOrder->cancel();
            }
            $reservationId = $storeOrder->getReservationId();
            if ($reservationId === null) {
                return;
            }
            $this->outboxService->record('inventory.reservation.release.requested.v1', 'inventory_reservation', $reservationId, [
                'reservationId' => $reservationId,
                'storeUuid' => $storeOrder->getStore()->getUuid(),
                'tradeOrderUuid' => $storeOrder->getTradeOrderUuid(),
                'storeOrderUuid' => $storeOrder->getUuid(),
                'reason' => 'trade_order_cancelled',
                'requestedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]);
        });
    }
}
