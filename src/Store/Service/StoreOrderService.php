<?php

declare(strict_types=1);

namespace App\Store\Service;

use App\Core\Service\BaseService;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\StoreOrderRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<StoreOrder> */
final class StoreOrderService extends BaseService implements StoreOrderServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly StoreOrderRepository $storeOrderRepository,
        private readonly ?StoreOutboxServiceInterface $outboxService = null,
    )
    {
        parent::__construct($container, StoreOrder::class);
    }

    public function accept(StoreOrder $storeOrder, ?string $reservationId = null): StoreOrder
    {
        return $this->transaction(function () use ($storeOrder, $reservationId): StoreOrder {
            if ($this->outboxService === null) {
                throw new \RuntimeException('Store outbox is not configured.');
            }
            $storeOrder->accept($reservationId);
            $this->outboxService->record('store.order.accepted.v1', 'store_order', $storeOrder->getUuid(), [
                'orderUuid' => $storeOrder->getTradeOrderUuid(),
                'storeOrderUuid' => $storeOrder->getUuid(),
                'storeUuid' => $storeOrder->getStore()->getUuid(),
                'acceptedAt' => $storeOrder->getAcceptedAt()?->format(DATE_ATOM),
                'reservationId' => $storeOrder->getReservationId(),
            ]);

            return $storeOrder;
        });
    }

    public function reject(StoreOrder $storeOrder, string $code, string $reason): StoreOrder
    {
        return $this->transaction(function () use ($storeOrder, $code, $reason): StoreOrder {
            if ($this->outboxService === null) {
                throw new \RuntimeException('Store outbox is not configured.');
            }
            $storeOrder->reject($code, $reason);
            $this->outboxService->record('store.order.rejected.v1', 'store_order', $storeOrder->getUuid(), [
                'orderUuid' => $storeOrder->getTradeOrderUuid(),
                'storeOrderUuid' => $storeOrder->getUuid(),
                'storeUuid' => $storeOrder->getStore()->getUuid(),
                'reasonCode' => $storeOrder->getRejectionCode(),
                'reason' => $storeOrder->getRejectionReason(),
                'rejectedAt' => $storeOrder->getRejectedAt()?->format(DATE_ATOM),
            ]);

            return $storeOrder;
        });
    }

    /** @param array<string, mixed>|null $fulfillmentData */
    public function fulfill(StoreOrder $storeOrder, ?array $fulfillmentData = null): StoreOrder
    {
        return $this->transaction(function () use ($storeOrder, $fulfillmentData): StoreOrder {
            $storeOrder->fulfill($fulfillmentData);

            return $storeOrder;
        });
    }

    public function verify(StoreOrder $storeOrder, string $verificationCode, ?string $verifiedBy = null): StoreOrder
    {
        return $this->transaction(function () use ($storeOrder, $verificationCode, $verifiedBy): StoreOrder {
            if ($this->outboxService === null) {
                throw new \RuntimeException('Store outbox is not configured.');
            }
            $storeOrder->verify($verificationCode, $verifiedBy);
            $this->outboxService->record('store.order.verified.v1', 'store_order', $storeOrder->getUuid(), [
                'orderUuid' => $storeOrder->getTradeOrderUuid(),
                'storeOrderUuid' => $storeOrder->getUuid(),
                'storeUuid' => $storeOrder->getStore()->getUuid(),
                'verificationCode' => $verificationCode,
                'verifiedBy' => $verifiedBy,
                'verifiedAt' => $storeOrder->getVerifiedAt()?->format(DATE_ATOM),
            ]);

            return $storeOrder;
        });
    }

    /** @param array<string, mixed> $snapshot */
    public function createFromTradeOrderSnapshot(Store $store, array $snapshot): StoreOrder
    {
        $data = $this->normalizeSnapshot($store, $snapshot);

        try {
            return $this->transaction(function () use ($store, $data): StoreOrder {
                $existing = $this->storeOrderRepository->findOneByTradeOrderUuid($data['tradeOrderUuid']);
                if ($existing !== null) {
                    if (!$this->matchesSnapshot($existing, $store, $data)) {
                        throw new \LogicException('Trade order snapshot conflicts with the existing Store order.');
                    }

                    return $existing;
                }

                $storeOrder = new StoreOrder(
                    $store,
                    $data['tradeOrderUuid'],
                    $data['storeCode'],
                    $data['storeName'],
                    $data['customerUserUuid'],
                    $data['currency'],
                    $data['totalAmount'],
                    $data['orderSnapshot'],
                );
                $this->getEntityManager()->persist($storeOrder);

                return $storeOrder;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->storeOrderRepository->findOneByTradeOrderUuid($data['tradeOrderUuid']);
            if ($existing === null) {
                throw $exception;
            }
            if (!$this->matchesSnapshot($existing, $store, $data)) {
                throw new \LogicException('Trade order snapshot conflicts with the existing Store order.', 0, $exception);
            }

            return $existing;
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{tradeOrderUuid: string, storeCode: string, storeName: string, customerUserUuid: string|null, currency: string, totalAmount: int, orderSnapshot: array<string, mixed>}
     */
    private function normalizeSnapshot(Store $store, array $snapshot): array
    {
        $storeSnapshot = $snapshot['store'] ?? null;
        if (!is_array($storeSnapshot)
            || ($storeSnapshot['uuid'] ?? null) !== $store->getUuid()
            || !is_string($storeSnapshot['code'] ?? null)
            || !is_string($storeSnapshot['name'] ?? null)
            || !is_string($snapshot['orderUuid'] ?? null)
            || !is_string($snapshot['currency'] ?? null)
            || !is_int($snapshot['totalAmount'] ?? null)
            || !is_array($snapshot['items'] ?? null)
            || !is_array($snapshot['delivery'] ?? null)
            || !is_string($snapshot['placedAt'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Trade order snapshot.');
        }

        $customerUserUuid = $snapshot['customerUserUuid'] ?? null;
        if ($customerUserUuid !== null && !is_string($customerUserUuid)) {
            throw new \InvalidArgumentException('Trade order customer user UUID must be a string or null.');
        }
        if ($snapshot['totalAmount'] < 0) {
            throw new \InvalidArgumentException('Trade order total amount cannot be negative.');
        }

        $orderSnapshot = [
            'items' => $snapshot['items'],
            'delivery' => $snapshot['delivery'],
            'placedAt' => $snapshot['placedAt'],
        ];
        if (isset($storeSnapshot['channel'])) {
            if (!is_string($storeSnapshot['channel'])) {
                throw new \InvalidArgumentException('Trade order store channel must be a string.');
            }
            $orderSnapshot['channel'] = $storeSnapshot['channel'];
        }

        return [
            'tradeOrderUuid' => $snapshot['orderUuid'],
            'storeCode' => $storeSnapshot['code'],
            'storeName' => $storeSnapshot['name'],
            'customerUserUuid' => $customerUserUuid,
            'currency' => strtoupper($snapshot['currency']),
            'totalAmount' => $snapshot['totalAmount'],
            'orderSnapshot' => $orderSnapshot,
        ];
    }

    /**
     * @param array{tradeOrderUuid: string, storeCode: string, storeName: string, customerUserUuid: string|null, currency: string, totalAmount: int, orderSnapshot: array<string, mixed>} $data
     */
    private function matchesSnapshot(StoreOrder $storeOrder, Store $store, array $data): bool
    {
        return $storeOrder->getStore()->getUuid() === $store->getUuid()
            && $storeOrder->getStoreCodeSnapshot() === $data['storeCode']
            && $storeOrder->getStoreNameSnapshot() === $data['storeName']
            && $storeOrder->getCustomerUserUuid() === $data['customerUserUuid']
            && $storeOrder->getCurrency() === $data['currency']
            && $storeOrder->getTotalAmount() === $data['totalAmount']
            && $storeOrder->getOrderSnapshot() === $data['orderSnapshot'];
    }

    private function transaction(callable $callback): mixed
    {
        try {
            if ($this->getEntityManager()->getConnection()->isTransactionActive()) {
                return $callback();
            }
        } catch (\Throwable) {
        }

        return $this->wrapInTransaction($callback);
    }
}
