<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store;

use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreOutboxService;
use App\Store\Service\StoreServiceInterface;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Message\TradeOrderCancelledMessage;
use Doctrine\ORM\EntityManagerInterface;

final class TradeOrderCreatedHandlerTest extends IntegrationWebTestCase
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

    public function testConsumesTradeOrderOnceAndPublishesAcceptance(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $stores = $container->get(StoreServiceInterface::class);
        $store = $stores->createStore('demo', 'Demo Store', 'Asia/Shanghai');
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $message = new TradeOrderCreatedMessage([
            'eventId' => 'a0d04d82-fb27-4b2d-8c54-2f896d4c6533',
            'payload' => [
                'orderUuid' => '96a1a1b2-4f86-44ff-94cb-41a1411ad0d8',
                'store' => [
                    'uuid' => $store->getUuid(),
                    'code' => $store->getCode(),
                    'name' => $store->getName(),
                    'channel' => 'api',
                ],
                'customerUserUuid' => null,
                'currency' => 'CNY',
                'totalAmount' => 12800,
                'items' => [],
                'delivery' => [],
                'placedAt' => '2026-07-25T00:00:00+00:00',
            ],
        ]);

        $handler($message);
        $handler($message);

        $orders = $container->get(StoreOrderRepository::class);
        $storeOrder = $orders->findOneByTradeOrderUuid('96a1a1b2-4f86-44ff-94cb-41a1411ad0d8');
        self::assertNotNull($storeOrder);
        self::assertSame('accepted', $storeOrder->getOperationalStatus());

        $outbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(0, $outbox);
    }

    public function testRejectsAnOrderForAnUnavailableStoreAndConsumesTheEvent(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);
        $message = new TradeOrderCreatedMessage([
            'eventId' => 'e2ac7552-a853-473c-a90a-899fb93d28f',
            'payload' => [
                'orderUuid' => 'e60b13bd-8e46-453f-b6b3-4b3bc59259b4',
                'store' => ['uuid' => 'c4843f0c-9ab8-4d5b-adc2-02c0f497a937'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store is not available.');
        $handler($message);
    }

    public function testRejectsMalformedTradeOrderEnvelopes(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trade.order.created.v1 envelope.');
        $handler(new TradeOrderCreatedMessage(['eventId' => 'event-id', 'payload' => 'invalid']));
    }

    public function testEnabledInventoryRequestsReservationInsteadOfAcceptingImmediately(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('inventory-enabled', 'Inventory Enabled', 'UTC');
        $handler = new \App\Store\MessageHandler\TradeOrderCreatedHandler(
            $container->get(StoreRepository::class),
            $container->get(StoreConsumedEventRepository::class),
            $container->get(StoreTradeOrderCancellationRepository::class),
            $container->get(StoreOrderServiceInterface::class),
            $container->get(StoreOutboxService::class),
            $container->get(EntityManagerInterface::class),
            true,
        );
        $handler(new TradeOrderCreatedMessage([
            'eventId' => '00000000-0000-4000-8000-000000000010',
            'payload' => [
                'orderUuid' => '00000000-0000-4000-8000-000000000011',
                'store' => ['uuid' => $store->getUuid(), 'code' => $store->getCode(), 'name' => $store->getName()],
                'currency' => 'CNY',
                'totalAmount' => 100,
                'items' => [[
                    'lineId' => '00000000-0000-4000-8000-000000000012',
                    'catalogReference' => '00000000-0000-4000-8000-000000000013',
                    'quantity' => 1,
                ]],
                'delivery' => [],
                'placedAt' => '2026-07-26T00:00:00+00:00',
            ],
        ]));

        $storeOrder = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid('00000000-0000-4000-8000-000000000011');
        self::assertNotNull($storeOrder);
        self::assertSame('awaiting_inventory', $storeOrder->getOperationalStatus());
        self::assertNotNull($storeOrder->getReservationId());
        $outbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $outbox);
        self::assertSame('inventory.reservation.requested.v1', $outbox[0]->getTopic());
        self::assertSame($storeOrder->getReservationId(), $outbox[0]->getPayload()['reservationId']);
    }

    public function testCancellationBeforeCreationCreatesCancelledOrderWithoutReservation(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('cancelled-first', 'Cancelled First', 'UTC');
        $orderUuid = '00000000-0000-4000-8000-000000000030';
        $cancel = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);
        $cancel(new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-000000000031',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => ['orderUuid' => $orderUuid, 'storeUuid' => $store->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        $create = $container->get(\App\Store\MessageHandler\TradeOrderCreatedHandler::class);
        $create(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-000000000032', 'payload' => $this->snapshot($store, $orderUuid)]));

        $order = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertSame(StoreOrder::STATUS_CANCELLED, $order?->getOperationalStatus());
        self::assertNull($order?->getReservationId());
        self::assertSame([], $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testDelayedCancellationDoesNotOverwriteRejectedOrFulfilledOrder(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $container->get(StoreServiceInterface::class)->createStore('terminal', 'Terminal', 'UTC');
        $rejected = new StoreOrder($store, '00000000-0000-4000-8000-000000000033', 'terminal', 'Terminal', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $rejected->reject('OUT_OF_STOCK', 'Unavailable');
        $fulfilled = new StoreOrder($store, '00000000-0000-4000-8000-000000000034', 'terminal', 'Terminal', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $fulfilled->fulfill();
        $em->persist($rejected);
        $em->persist($fulfilled);
        $em->flush();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);
        foreach ([$rejected, $fulfilled] as $index => $order) {
            $handler(new TradeOrderCancelledMessage(['eventId' => sprintf('00000000-0000-4000-8000-00000000003%d', $index + 5), 'type' => 'trade.order.cancelled', 'version' => 1, 'payload' => ['orderUuid' => $order->getTradeOrderUuid(), 'storeUuid' => $store->getUuid(), 'cancelledAt' => '2026-07-26T00:00:00+00:00']]));
        }

        $orders = $container->get(StoreOrderRepository::class);
        self::assertSame(StoreOrder::STATUS_REJECTED, $orders->findOneByUuid($rejected->getUuid())?->getOperationalStatus());
        self::assertSame(StoreOrder::STATUS_FULFILLED, $orders->findOneByUuid($fulfilled->getUuid())?->getOperationalStatus());
    }

    public function testCancellationRequiresTimestampAndMatchingStore(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $store = $container->get(StoreServiceInterface::class)->createStore('cancel-validation', 'Cancel Validation', 'UTC');
        $order = new StoreOrder($store, '00000000-0000-4000-8000-000000000037', 'cancel-validation', 'Cancel Validation', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $container->get(EntityManagerInterface::class)->persist($order);
        $container->get(EntityManagerInterface::class)->flush();
        $handler = $container->get(\App\Store\MessageHandler\TradeOrderCancelledHandler::class);

        $handler(new TradeOrderCancelledMessage(['eventId' => '00000000-0000-4000-8000-000000000038', 'type' => 'trade.order.cancelled', 'version' => 1, 'payload' => ['orderUuid' => $order->getTradeOrderUuid(), 'storeUuid' => '00000000-0000-4000-8000-000000000039', 'cancelledAt' => '2026-07-26T00:00:00+00:00']]));
        self::assertSame(StoreOrder::STATUS_PENDING_VALIDATION, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());

        $this->expectException(\InvalidArgumentException::class);
        $handler(new TradeOrderCancelledMessage(['eventId' => '00000000-0000-4000-8000-000000000041', 'type' => 'trade.order.cancelled', 'version' => 1, 'payload' => ['orderUuid' => $order->getTradeOrderUuid(), 'storeUuid' => $store->getUuid(), 'cancelledAt' => 'not-a-date']]));
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
