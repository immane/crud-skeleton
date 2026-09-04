<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store\MessageHandler;

use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Entity\StoreTradeOrderCancellation;
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

final class TradeOrderCreatedHandlerCoverageTest extends IntegrationWebTestCase
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

    public function testCreatedEventWithoutStoreSnapshotThrows(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order event does not include a store UUID.');
        $handler(new TradeOrderCreatedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000L1',
            'payload' => ['orderUuid' => '00000000-0000-4000-8000-0000000000L2'],
        ]));
    }

    public function testCreatedEventWithStoreSnapshotWithoutUuidThrows(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order event does not include a store UUID.');
        $handler(new TradeOrderCreatedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000L3',
            'payload' => ['orderUuid' => '00000000-0000-4000-8000-0000000000L4', 'store' => ['code' => 'xuhui', 'name' => 'Xuhui']],
        ]));
    }

    public function testConflictingCancellationTombstoneThrows(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $storeA = $container->get(StoreServiceInterface::class)->createStore('create-conf-a', 'Create Conf A', 'UTC');
        $storeB = $container->get(StoreServiceInterface::class)->createStore('create-conf-b', 'Create Conf B', 'UTC');
        $orderUuid = '00000000-0000-4000-8000-0000000000L5';
        $em->persist(new StoreTradeOrderCancellation($orderUuid, $storeA->getUuid(), new \DateTimeImmutable('2026-07-26T00:00:00+00:00')));
        $em->flush();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Trade order cancellation conflicts with the Store order snapshot.');
        $handler(new TradeOrderCreatedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000L6',
            'payload' => $this->snapshot($storeB, $orderUuid),
        ]));
    }

    public function testDuplicateCreatedEventWithDifferentEventIdIsIgnored(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('create-dupe', 'Create Dupe', 'UTC');
        $orderUuid = '00000000-0000-4000-8000-0000000000L7';
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $handler(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-0000000000L8', 'payload' => $this->snapshot($store, $orderUuid)]));
        $handler(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-0000000000L9', 'payload' => $this->snapshot($store, $orderUuid)]));

        $orders = $container->get(StoreOrderRepository::class)->findBy(['tradeOrderUuid' => $orderUuid]);
        self::assertCount(1, $orders);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $orders[0]->getOperationalStatus());
        $accepted = array_filter($container->get(StoreOutboxMessageRepository::class)->findUnpublished(), static fn ($message): bool => $message->getTopic() === 'store.order.accepted.v1');
        self::assertCount(0, $accepted);
    }

    public function testUnavailableStoreWithoutOrderUuidThrows(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order event does not include an order UUID.');
        $handler(new TradeOrderCreatedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000M1',
            'payload' => ['store' => ['uuid' => '00000000-0000-4000-8000-0000000000M2']],
        ]));
    }

    public function testInventoryEnabledEmptyItemsThrows(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('inv-empty', 'Inventory Empty', 'UTC');
        $handler = $this->inventoryEnabledHandler($container);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order event does not include inventory items.');
        $handler(new TradeOrderCreatedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000M3',
            'payload' => $this->snapshot($store, '00000000-0000-4000-8000-0000000000M4'),
        ]));
    }

    public function testInventoryEnabledZeroQuantityItemThrows(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('inv-zero', 'Inventory Zero', 'UTC');
        $handler = $this->inventoryEnabledHandler($container);
        $payload = $this->snapshot($store, '00000000-0000-4000-8000-0000000000M5');
        $payload['items'] = [[
            'lineId' => '00000000-0000-4000-8000-0000000000M6',
            'catalogReference' => '00000000-0000-4000-8000-0000000000M7',
            'quantity' => 0,
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order event includes an invalid inventory item.');
        $handler(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-0000000000M8', 'payload' => $payload]));
    }

    public function testInventoryEnabledItemMissingCatalogReferenceThrows(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('inv-missing', 'Inventory Missing', 'UTC');
        $handler = $this->inventoryEnabledHandler($container);
        $payload = $this->snapshot($store, '00000000-0000-4000-8000-0000000000M9');
        $payload['items'] = [[
            'lineId' => '00000000-0000-4000-8000-0000000000N1',
            'quantity' => 1,
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order event includes an invalid inventory item.');
        $handler(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-0000000000N2', 'payload' => $payload]));
    }

    public function testInventoryEnabledNonIntegerQuantityThrows(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('inv-num', 'Inventory Num', 'UTC');
        $handler = $this->inventoryEnabledHandler($container);
        $payload = $this->snapshot($store, '00000000-0000-4000-8000-0000000000N3');
        $payload['items'] = [[
            'lineId' => '00000000-0000-4000-8000-0000000000N4',
            'catalogReference' => '00000000-0000-4000-8000-0000000000N5',
            'quantity' => '1',
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trade order event includes an invalid inventory item.');
        $handler(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-0000000000N6', 'payload' => $payload]));
    }

    private function inventoryEnabledHandler(\Symfony\Component\DependencyInjection\ContainerInterface $container): \App\Store\MessageHandler\TradeOrderCreatedHandler
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

    /** @return array<string, mixed> */
    private function snapshot(Store $store, string $orderUuid): array
    {
        return [
            'orderUuid' => $orderUuid,
            'store' => ['uuid' => $store->getUuid(), 'code' => $store->getCode(), 'name' => $store->getName()],
            'currency' => 'CNY',
            'totalAmount' => 100,
            'items' => [],
            'delivery' => [],
            'placedAt' => '2026-07-26T00:00:00+00:00',
        ];
    }
}
