<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Entity\User;
use App\Store\Entity\StoreOrder;
use App\Store\MessageHandler\TradeOrderCancelledHandler;
use App\Store\MessageHandler\TradeOrderCreatedHandler;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\MembershipServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Message\TradeOrderCancelledMessage;
use App\Trade\Message\TradeOrderCreatedMessage;
use App\Trade\Repository\TradeOutboxMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * End-to-end Store <-> Trade flows with inventory disabled (INVENTORY_ENABLED=0,
 * the default): acceptance, rejection, idempotency / dedup, unknown-store and
 * out-of-order tombstone paths, all driven through the HTTP API + real outbox
 * publish commands on a synchronous MessageBus.
 */
final class StoreTradeFlowTest extends StoreTradeFlowTestCase
{
    public function testFullTradeToStoreAcceptanceThroughHttpAndOutboxCommands(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $this->createStore($container, 'e2e-accept');
        [$product, $specification] = $this->createProduct($em, 'E2E Accept Product');

        $placed = $this->placeStoreOrder($client, $store->getCode(), (int) $specification->getId());
        $orderUuid = $placed['uuid'];

        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('awaiting_store_acceptance', $order->getStatus());

        $tradeOutbox = $container->get(TradeOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $tradeOutbox);
        self::assertSame('trade.order.created.v1', $tradeOutbox[0]->getTopic());

        $tester = $this->tradePublish($container);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Published 1 Trade outbox message(s).', $tester->getDisplay());
        self::assertCount(0, $container->get(TradeOutboxMessageRepository::class)->findUnpublished());

        $storeOrder = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($orderUuid);
        self::assertInstanceOf(StoreOrder::class, $storeOrder);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $storeOrder->getOperationalStatus());
        self::assertSame($store->getUuid(), $storeOrder->getStore()->getUuid());

        $storeOutbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $storeOutbox);
        self::assertSame('store.order.accepted.v1', $storeOutbox[0]->getTopic());
        self::assertSame($orderUuid, $storeOutbox[0]->getPayload()['orderUuid']);

        $tester = $this->storePublish($container);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Published 1 Store outbox message(s).', $tester->getDisplay());

        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('store_accepted', $order->getStatus());

        $tester = $this->storePublish($container);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Published 0 Store outbox message(s).', $tester->getDisplay());

        $client->request('POST', '/api/v1/app/orders/' . $placed['id'] . '/confirm');
        self::assertResponseIsSuccessful();
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('confirmed', $order->getStatus());
    }

    public function testStoreRejectionLeavesTradeOrderInStoreRejectedUntilExplicitCancel(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $this->createStore($container, 'e2e-reject');
        [$product, $specification] = $this->createProduct($em, 'E2E Reject Product');
        $placed = $this->placeStoreOrder($client, $store->getCode(), (int) $specification->getId());
        $orderUuid = $placed['uuid'];

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);

        $storeOrder = new StoreOrder(
            $store,
            $orderUuid,
            $store->getCode(),
            $store->getName(),
            $user->getUuid(),
            'CNY',
            12800,
            ['items' => [], 'delivery' => [], 'placedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)],
        );
        $em->persist($storeOrder);
        $em->flush();
        $container->get(MembershipServiceInterface::class)->grant($store, (string) $user->getUuid(), 'manager');

        $client->request('POST', '/api/v1/store/' . $store->getUuid() . '/orders/' . $storeOrder->getUuid() . '/reject', [], [], [], json_encode([
            'code' => 'OUT_OF_STOCK',
            'reason' => 'Unavailable.',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $storeOutbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $storeOutbox);
        self::assertSame('store.order.rejected.v1', $storeOutbox[0]->getTopic());
        self::assertSame('OUT_OF_STOCK', $storeOutbox[0]->getPayload()['reasonCode']);

        $this->storePublish($container);

        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('store_rejected', $order->getStatus());

        // cancel only from [draft, pending, confirmed] — store_rejected must not cancel
        $client->request('POST', '/api/v1/app/orders/' . $placed['id'] . '/cancel');
        self::assertResponseStatusCodeSame(400);
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['uuid' => $orderUuid]);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('store_rejected', $order->getStatus());
    }

    public function testStoreBecomingUnavailableAfterPlacementRejectsTheOrder(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $this->createStore($container, 'e2e-unavail');
        [$product, $specification] = $this->createProduct($em, 'E2E Unavailable Product');
        $placed = $this->placeStoreOrder($client, $store->getCode(), (int) $specification->getId());

        $store->suspend();
        $em->flush();

        $this->tradePublish($container);

        self::assertNull($container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($placed['uuid']));
        $storeOutbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $storeOutbox);
        self::assertSame('store.order.rejected.v1', $storeOutbox[0]->getTopic());
        self::assertSame('STORE_UNAVAILABLE', $storeOutbox[0]->getPayload()['reasonCode']);

        $this->storePublish($container);

        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['uuid' => $placed['uuid']]);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('store_rejected', $order->getStatus());
    }

    public function testUnknownStoreCodeReturnsErrorAndCreatesNoOrder(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $client->setServerParameter('HTTP_X_STORE_CODE', 'no-such-store');
        $client->jsonRequest('POST', '/api/v1/app/orders', [
            'currency' => 'CNY',
            'items' => [['specificationId' => 1, 'quantity' => 1]],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $container->get(TradeOutboxMessageRepository::class)->findUnpublished());
        self::assertCount(0, $container->get(StoreOrderRepository::class)->findAll());
    }

    public function testDuplicateEventDeliveryIsDeduplicatedByInbox(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $this->createStore($container, 'e2e-dedup');
        [$product, $specification] = $this->createProduct($em, 'E2E Dedup Product');
        $placed = $this->placeStoreOrder($client, $store->getCode(), (int) $specification->getId());

        $tradeOutbox = $container->get(TradeOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $tradeOutbox);
        $handler = $container->get(TradeOrderCreatedHandler::class);
        $message = new TradeOrderCreatedMessage([
            'eventId' => $tradeOutbox[0]->getEventId(),
            'payload' => $tradeOutbox[0]->getPayload(),
        ]);
        $handler($message);
        $handler($message);

        $storeOrders = $container->get(StoreOrderRepository::class)->findBy(['tradeOrderUuid' => $placed['uuid']]);
        self::assertCount(1, $storeOrders);
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $storeOrders[0]->getOperationalStatus());
        $storeOutbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $storeOutbox);
        self::assertSame('store.order.accepted.v1', $storeOutbox[0]->getTopic());
        self::assertCount(1, $container->get(StoreConsumedEventRepository::class)->findBy(['eventId' => $tradeOutbox[0]->getEventId()]));
    }

    public function testStoreOrderAlreadyProcessedIsIdempotentAcrossEventIds(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $this->createStore($container, 'e2e-idem');
        [$product, $specification] = $this->createProduct($em, 'E2E Idempotent Product');
        $placed = $this->placeStoreOrder($client, $store->getCode(), (int) $specification->getId());

        $tradeOutbox = $container->get(TradeOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $tradeOutbox);
        $payload = $tradeOutbox[0]->getPayload();
        $handler = $container->get(TradeOrderCreatedHandler::class);
        $handler(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-000000000100', 'payload' => $payload]));
        $handler(new TradeOrderCreatedMessage(['eventId' => '00000000-0000-4000-8000-000000000101', 'payload' => $payload]));

        $storeOrders = $container->get(StoreOrderRepository::class)->findBy(['tradeOrderUuid' => $placed['uuid']]);
        self::assertCount(1, $storeOrders);
        $storeOutbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(1, $storeOutbox);
        self::assertSame('store.order.accepted.v1', $storeOutbox[0]->getTopic());
    }

    public function testCancellationForUnknownStoreOrderPersistsTombstoneAndIsIdempotent(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $handler = $container->get(TradeOrderCancelledHandler::class);
        $message = new TradeOrderCancelledMessage([
            'eventId' => '00000000-0000-4000-8000-000000000200',
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => [
                'orderUuid' => '00000000-0000-4000-8000-000000000201',
                'storeUuid' => '00000000-0000-4000-8000-000000000202',
                'cancelledAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
        ]);
        $handler($message);
        $handler($message);

        $tombstones = $container->get(StoreTradeOrderCancellationRepository::class)->findBy(['tradeOrderUuid' => '00000000-0000-4000-8000-000000000201']);
        self::assertCount(1, $tombstones);
        self::assertSame('00000000-0000-4000-8000-000000000202', $tombstones[0]->getStoreUuid());
        self::assertNull($container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid('00000000-0000-4000-8000-000000000201'));
    }

    public function testOutOfOrderCancellationTombstoneIsHonoredWhenOrderCreatedLater(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = $this->createStore($container, 'e2e-tombstone');
        [$product, $specification] = $this->createProduct($em, 'E2E Tombstone Product');
        $placed = $this->placeStoreOrder($client, $store->getCode(), (int) $specification->getId());

        // cancel only from [draft, pending, confirmed] — reset to draft before cancelling to keep tombstone flow
        $em->clear();
        $tmp = $em->getRepository(Order::class)->findOneBy(['uuid' => $placed['uuid']]);
        self::assertInstanceOf(Order::class, $tmp);
        $tmp->setStatus(Order::STATUS_DRAFT);
        $em->flush();

        $client->request('POST', '/api/v1/app/orders/' . $placed['id'] . '/cancel');
        self::assertResponseIsSuccessful();
        $em->clear();
        $chk = $em->getRepository(Order::class)->findOneBy(['uuid' => $placed['uuid']]);
        self::assertInstanceOf(Order::class, $chk);
        self::assertSame('cancelled', $chk->getStatus());

        $tradeOutbox = $container->get(TradeOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(2, $tradeOutbox);
        $created = null;
        $cancelled = null;
        foreach ($tradeOutbox as $message) {
            match ($message->getTopic()) {
                'trade.order.created.v1' => $created = $message,
                'trade.order.cancelled.v1' => $cancelled = $message,
                default => self::fail('Unexpected trade outbox topic ' . $message->getTopic()),
            };
        }
        self::assertNotNull($created);
        self::assertNotNull($cancelled);

        $handler = $container->get(TradeOrderCancelledHandler::class);
        $handler(new TradeOrderCancelledMessage([
            'eventId' => $cancelled->getEventId(),
            'type' => 'trade.order.cancelled',
            'version' => 1,
            'payload' => $cancelled->getPayload(),
        ]));
        self::assertCount(1, $container->get(StoreTradeOrderCancellationRepository::class)->findBy(['tradeOrderUuid' => $placed['uuid']]));
        self::assertNull($container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($placed['uuid']));

        $this->tradePublish($container);

        $em->clear();
        $storeOrder = $container->get(StoreOrderRepository::class)->findOneByTradeOrderUuid($placed['uuid']);
        self::assertInstanceOf(StoreOrder::class, $storeOrder);
        self::assertSame(StoreOrder::STATUS_CANCELLED, $storeOrder->getOperationalStatus());
        self::assertNull($storeOrder->getReservationId());
        self::assertSame([], $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }
}
