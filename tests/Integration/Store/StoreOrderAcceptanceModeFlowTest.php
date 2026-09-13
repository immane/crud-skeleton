<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store;

use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreOutboxService;
use App\Store\Service\StoreServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Message\TradeOrderCreatedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StoreOrderAcceptanceModeFlowTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOutboxMessage message')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreConsumedEvent event')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreTradeOrderCancellation cancellation')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOrder storeOrder')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\Store store')->execute();
        self::ensureKernelShutdown();
    }

    public function testMode0AutoAcceptsWithoutInventory(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $this->createStore($container, 'mode0', []);
        $orderUuid = '10000000-0000-4000-8000-000000000001';

        $this->handle($container, '20000000-0000-4000-8000-000000000001', $this->snapshot($store, $orderUuid, []));

        $order = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertInstanceOf(StoreOrder::class, $order);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $order->getOperationalStatus());
        self::assertSame(['store.order.accepted.v1'], $this->outboxTopics($container));
    }

    public function testMode1WaitsForManualAccept(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $this->createStore($container, 'mode1', ['requireAcceptance' => true]);
        $orderUuid = '10000000-0000-4000-8000-000000000002';

        $this->handle($container, '20000000-0000-4000-8000-000000000002', $this->snapshot($store, $orderUuid, ['requireAcceptance' => true]));

        $order = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertInstanceOf(StoreOrder::class, $order);
        self::assertSame(StoreOrder::STATUS_PENDING_VALIDATION, $order->getOperationalStatus());
        self::assertSame([], $this->outboxTopics($container));

        $container->get(StoreOrderServiceInterface::class)->accept($order);

        $stored = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $stored?->getOperationalStatus());
        self::assertSame(['store.order.accepted.v1'], $this->outboxTopics($container));
    }

    public function testMode2AutoAcceptsAfterInventoryConfirmation(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $this->createStore($container, 'mode2', ['requireInventory' => true]);
        $orderUuid = '10000000-0000-4000-8000-000000000003';

        $this->inventoryHandler($container)->__invoke(new TradeOrderCreatedMessage([
            'eventId' => '20000000-0000-4000-8000-000000000003',
            'payload' => $this->snapshot($store, $orderUuid, ['requireInventory' => true], true),
        ]));

        $order = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertInstanceOf(StoreOrder::class, $order);
        self::assertSame(StoreOrder::STATUS_AWAITING_INVENTORY, $order->getOperationalStatus());
        self::assertSame(['inventory.reservation.requested.v1'], $this->outboxTopics($container));

        $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class)(new \App\Inventory\Message\ReservationConfirmedMessage([
            'eventId' => '20000000-0000-4000-8000-000000000004',
            'type' => 'inventory.reservation.confirmed',
            'version' => 1,
            'payload' => [
                'reservationId' => $order->getReservationId(),
                'storeUuid' => $store->getUuid(),
                'tradeOrderUuid' => $orderUuid,
                'storeOrderUuid' => $order->getUuid(),
                'confirmedAt' => '2026-07-26T00:00:00+00:00',
            ],
        ]));

        $stored = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $stored?->getOperationalStatus());
    }

    public function testMode3HoldsForManualAcceptAfterInventoryConfirmation(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $this->createStore($container, 'mode3', ['requireAcceptance' => true, 'requireInventory' => true]);
        $orderUuid = '10000000-0000-4000-8000-000000000004';

        $this->inventoryHandler($container)->__invoke(new TradeOrderCreatedMessage([
            'eventId' => '20000000-0000-4000-8000-000000000005',
            'payload' => $this->snapshot($store, $orderUuid, ['requireAcceptance' => true, 'requireInventory' => true], true),
        ]));

        $order = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertInstanceOf(StoreOrder::class, $order);
        self::assertSame(StoreOrder::STATUS_AWAITING_INVENTORY, $order->getOperationalStatus());

        $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class)(new \App\Inventory\Message\ReservationConfirmedMessage([
            'eventId' => '20000000-0000-4000-8000-000000000006',
            'type' => 'inventory.reservation.confirmed',
            'version' => 1,
            'payload' => [
                'reservationId' => $order->getReservationId(),
                'storeUuid' => $store->getUuid(),
                'tradeOrderUuid' => $orderUuid,
                'storeOrderUuid' => $order->getUuid(),
                'confirmedAt' => '2026-07-26T00:00:00+00:00',
            ],
        ]));

        $stored = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertSame(StoreOrder::STATUS_AWAITING_INVENTORY, $stored?->getOperationalStatus());
        self::assertSame(['inventory.reservation.requested.v1'], $this->outboxTopics($container));

        $container->get(StoreOrderServiceInterface::class)->accept($stored);

        $accepted = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $accepted?->getOperationalStatus());
    }

    public function testRedeliveryIgnoresChangedPolicyFlags(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $this->createStore($container, 'mode-snap', []);
        $orderUuid = '10000000-0000-4000-8000-000000000005';

        $this->handle($container, '20000000-0000-4000-8000-000000000007', $this->snapshot($store, $orderUuid, []));

        // Policy flags live in the event payload, not on the StoreOrder entity: a
        // redelivery carrying changed flags still matches the existing order and
        // stays idempotent instead of conflicting.
        $this->handle($container, '20000000-0000-4000-8000-000000000008', $this->snapshot($store, $orderUuid, ['requireAcceptance' => true]));

        $order = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertInstanceOf(StoreOrder::class, $order);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $order->getOperationalStatus());
        self::assertSame(['store.order.accepted.v1'], $this->outboxTopics($container));
    }

    public function testNonBooleanAcceptanceFlagIsRejected(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $this->createStore($container, 'mode-bad', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order store requireAcceptance must be a boolean.');
        $this->handle($container, '20000000-0000-4000-8000-000000000009', $this->snapshot($store, '10000000-0000-4000-8000-000000000006', ['requireAcceptance' => 'yes']));
    }

    private function handle(ContainerInterface $container, string $eventId, array $payload): void
    {
        $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class)(new TradeOrderCreatedMessage([
            'eventId' => $eventId,
            'payload' => $payload,
        ]));
    }

    private function inventoryHandler(ContainerInterface $container): \App\Store\MessageHandler\TradeOrderCreatedHandler
    {
        return new \App\Store\MessageHandler\TradeOrderCreatedHandler(
            $container->get(StoreRepository::class),
            $container->get(StoreConsumedEventRepository::class),
            $container->get(StoreTradeOrderCancellationRepository::class),
            $container->get(StoreOrderServiceInterface::class),
            $container->get(StoreOutboxService::class),
            $container->get(EntityManagerInterface::class),
            true,
        );
    }

    private function createStore(ContainerInterface $container, string $code, array $orderSettings): Store
    {
        $store = $container->get(StoreServiceInterface::class)->createStore($code, ucfirst($code) . ' Store', 'UTC');
        if ($orderSettings !== []) {
            $store->setSettings(['order' => $orderSettings]);
        }
        $container->get(EntityManagerInterface::class)->flush();

        return $store;
    }

    /**
     * @param array<string, mixed> $flags
     * @return array<string, mixed>
     */
    private function snapshot(Store $store, string $orderUuid, array $flags, bool $withItems = false): array
    {
        return [
            'orderUuid' => $orderUuid,
            'store' => array_merge(
                ['uuid' => $store->getUuid(), 'code' => $store->getCode(), 'name' => $store->getName()],
                $flags,
            ),
            'currency' => 'CNY',
            'totalAmount' => 100,
            'items' => $withItems ? [[
                'lineId' => '30000000-0000-4000-8000-000000000001',
                'catalogReference' => '30000000-0000-4000-8000-000000000002',
                'quantity' => 1,
            ]] : [],
            'delivery' => [],
            'placedAt' => '2026-07-26T00:00:00+00:00',
        ];
    }

    /** @return list<string> */
    private function outboxTopics(ContainerInterface $container): array
    {
        return array_map(
            static fn ($message): string => $message->getTopic(),
            $container->get(StoreOutboxMessageRepository::class)->findUnpublished(),
        );
    }
}
