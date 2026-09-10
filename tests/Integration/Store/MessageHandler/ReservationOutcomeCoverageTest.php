<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store\MessageHandler;

use App\Inventory\Message\ReservationConfirmedMessage;
use App\Inventory\Message\ReservationRejectedMessage;
use App\Inventory\Message\ReservationReleasedMessage;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreOrderRepository;
use App\Store\Repository\StoreOutboxMessageRepository;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ReservationOutcomeCoverageTest extends IntegrationWebTestCase
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
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\StoreOrder storeOrder')->execute();
        $entityManager->createQuery('DELETE FROM App\\Store\\Entity\\Store store')->execute();
        self::ensureKernelShutdown();
    }

    // ------------------------------------------------------------------
    // ReservationConfirmedHandler
    // ------------------------------------------------------------------

    public function testConfirmationRejectsEnvelopeWithWrongType(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory.reservation.confirmed.v1 envelope.');
        $handler(new ReservationConfirmedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000E1',
            'type' => 'inventory.reservation.released',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'storeUuid' => 's', 'tradeOrderUuid' => 't', 'storeOrderUuid' => 'o', 'confirmedAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testConfirmationRejectsEnvelopeWithNonArrayPayload(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory.reservation.confirmed.v1 envelope.');
        $handler(new ReservationConfirmedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000E2',
            'type' => 'inventory.reservation.confirmed',
            'version' => 1,
            'payload' => 'not-an-array',
        ]));
    }

    public function testConfirmationRejectsPayloadMissingARequiredField(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory reservation confirmation payload.');
        $handler(new ReservationConfirmedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000E3',
            'type' => 'inventory.reservation.confirmed',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'storeUuid' => 's', 'tradeOrderUuid' => 't', 'storeOrderUuid' => 'o'],
        ]));
    }

    public function testConfirmationIsIgnoredForUnknownStoreOrder(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $handler = $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);

        $handler(new ReservationConfirmedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000E4',
            'type' => 'inventory.reservation.confirmed',
            'version' => 1,
            'payload' => ['reservationId' => '00000000-0000-4000-8000-0000000000E5', 'storeUuid' => '00000000-0000-4000-8000-0000000000E6', 'tradeOrderUuid' => '00000000-0000-4000-8000-0000000000E7', 'storeOrderUuid' => '00000000-0000-4000-8000-0000000000E8', 'confirmedAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
        self::assertNull($container->get(StoreOrderRepository::class)->findOneByUuid('00000000-0000-4000-8000-0000000000E8'));
    }

    public function testConfirmationIsIgnoredWhenStoreDoesNotMatch(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);
        $payload = $this->outcomePayload($order, ['confirmedAt' => '2026-07-26T00:00:00+00:00']);
        $payload['storeUuid'] = '00000000-0000-4000-8000-0000000000F1';

        $handler(new ReservationConfirmedMessage(['eventId' => '00000000-0000-4000-8000-0000000000F2', 'type' => 'inventory.reservation.confirmed', 'version' => 1, 'payload' => $payload]));

        self::assertSame(StoreOrder::STATUS_AWAITING_INVENTORY, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testConfirmationIsIgnoredWhenTradeOrderDoesNotMatch(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);
        $payload = $this->outcomePayload($order, ['confirmedAt' => '2026-07-26T00:00:00+00:00']);
        $payload['tradeOrderUuid'] = '00000000-0000-4000-8000-0000000000F3';

        $handler(new ReservationConfirmedMessage(['eventId' => '00000000-0000-4000-8000-0000000000F4', 'type' => 'inventory.reservation.confirmed', 'version' => 1, 'payload' => $payload]));

        self::assertSame(StoreOrder::STATUS_AWAITING_INVENTORY, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testConfirmationIsIgnoredWhenReservationDoesNotMatch(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);
        $payload = $this->outcomePayload($order, ['confirmedAt' => '2026-07-26T00:00:00+00:00']);
        $payload['reservationId'] = '00000000-0000-4000-8000-0000000000F5';

        $handler(new ReservationConfirmedMessage(['eventId' => '00000000-0000-4000-8000-0000000000F6', 'type' => 'inventory.reservation.confirmed', 'version' => 1, 'payload' => $payload]));

        self::assertSame(StoreOrder::STATUS_AWAITING_INVENTORY, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testConfirmationIsIgnoredWhenOrderIsNotAwaitingInventory(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $order->accept();
        $container->get(EntityManagerInterface::class)->flush();
        $handler = $container->get(\App\Store\MessageHandler\ReservationConfirmedHandler::class);

        $handler(new ReservationConfirmedMessage(['eventId' => '00000000-0000-4000-8000-0000000000F7', 'type' => 'inventory.reservation.confirmed', 'version' => 1, 'payload' => $this->outcomePayload($order, ['confirmedAt' => '2026-07-26T00:00:00+00:00'])]));

        self::assertSame(StoreOrder::STATUS_ACCEPTED, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    // ------------------------------------------------------------------
    // ReservationRejectedHandler
    // ------------------------------------------------------------------

    public function testRejectionRejectsEnvelopeWithWrongVersion(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory.reservation.rejected.v1 envelope.');
        $handler(new ReservationRejectedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000F8',
            'type' => 'inventory.reservation.rejected',
            'version' => 2,
            'payload' => ['reservationId' => 'r', 'storeUuid' => 's', 'tradeOrderUuid' => 't', 'storeOrderUuid' => 'o', 'reasonCode' => 'X', 'reason' => 'y', 'rejectedAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testRejectionRejectsPayloadMissingARequiredField(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory reservation rejection payload.');
        $handler(new ReservationRejectedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000F9',
            'type' => 'inventory.reservation.rejected',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'storeUuid' => 's', 'tradeOrderUuid' => 't', 'storeOrderUuid' => 'o', 'reasonCode' => 'X', 'reason' => 'y'],
        ]));
    }

    public function testRejectionRejectsPayloadWithNonStringReason(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory reservation rejection payload.');
        $handler(new ReservationRejectedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000G1',
            'type' => 'inventory.reservation.rejected',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'storeUuid' => 's', 'tradeOrderUuid' => 't', 'storeOrderUuid' => 'o', 'reasonCode' => 'X', 'reason' => 42, 'rejectedAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testDuplicateRejectionEventIsIgnored(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);
        $message = new ReservationRejectedMessage(['eventId' => '00000000-0000-4000-8000-0000000000G2', 'type' => 'inventory.reservation.rejected', 'version' => 1, 'payload' => $this->outcomePayload($order, ['reasonCode' => 'OUT_OF_STOCK', 'reason' => 'No stock.', 'rejectedAt' => '2026-07-26T00:00:00+00:00'])]);

        $handler($message);
        $handler($message);

        $stored = $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid());
        self::assertSame(StoreOrder::STATUS_REJECTED, $stored?->getOperationalStatus());
        self::assertSame('OUT_OF_STOCK', $stored?->getRejectionCode());
        $outbox = $container->get(StoreOutboxMessageRepository::class)->findUnpublished();
        self::assertCount(0, $outbox);
    }

    public function testRejectionIsIgnoredForUnknownStoreOrder(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $handler = $container->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);

        $handler(new ReservationRejectedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000G3',
            'type' => 'inventory.reservation.rejected',
            'version' => 1,
            'payload' => ['reservationId' => '00000000-0000-4000-8000-0000000000G4', 'storeUuid' => '00000000-0000-4000-8000-0000000000G5', 'tradeOrderUuid' => '00000000-0000-4000-8000-0000000000G6', 'storeOrderUuid' => '00000000-0000-4000-8000-0000000000G7', 'reasonCode' => 'X', 'reason' => 'y', 'rejectedAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
        self::assertNull($container->get(StoreOrderRepository::class)->findOneByUuid('00000000-0000-4000-8000-0000000000G7'));
    }

    public function testRejectionIsIgnoredWhenStoreDoesNotMatch(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $handler = $container->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);
        $payload = $this->outcomePayload($order, ['reasonCode' => 'OUT_OF_STOCK', 'reason' => 'No stock.', 'rejectedAt' => '2026-07-26T00:00:00+00:00']);
        $payload['storeUuid'] = '00000000-0000-4000-8000-0000000000G8';

        $handler(new ReservationRejectedMessage(['eventId' => '00000000-0000-4000-8000-0000000000G9', 'type' => 'inventory.reservation.rejected', 'version' => 1, 'payload' => $payload]));

        self::assertSame(StoreOrder::STATUS_AWAITING_INVENTORY, $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid())?->getOperationalStatus());
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    public function testRejectionIsIgnoredWhenOrderIsNotAwaitingInventory(): void
    {
        [$container, $order] = $this->awaitingOrder();
        $order->reject('MANUAL', 'manual');
        $container->get(EntityManagerInterface::class)->flush();
        $handler = $container->get(\App\Store\MessageHandler\ReservationRejectedHandler::class);

        $handler(new ReservationRejectedMessage(['eventId' => '00000000-0000-4000-8000-0000000000H1', 'type' => 'inventory.reservation.rejected', 'version' => 1, 'payload' => $this->outcomePayload($order, ['reasonCode' => 'OUT_OF_STOCK', 'reason' => 'No stock.', 'rejectedAt' => '2026-07-26T00:00:00+00:00'])]));

        $stored = $container->get(StoreOrderRepository::class)->findOneByUuid($order->getUuid());
        self::assertSame('MANUAL', $stored?->getRejectionCode());
        self::assertCount(0, $container->get(StoreOutboxMessageRepository::class)->findUnpublished());
    }

    // ------------------------------------------------------------------
    // ReservationReleasedHandler
    // ------------------------------------------------------------------

    public function testReleaseRejectsEnvelopeWithWrongType(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationReleasedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory.reservation.released.v1 envelope.');
        $handler(new ReservationReleasedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000H2',
            'type' => 'inventory.reservation.confirmed',
            'version' => 1,
            'payload' => ['reservationId' => 'r', 'releasedAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testReleaseRejectsPayloadMissingReleasedAt(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationReleasedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory.reservation.released.v1 envelope.');
        $handler(new ReservationReleasedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000H3',
            'type' => 'inventory.reservation.released',
            'version' => 1,
            'payload' => ['reservationId' => 'r'],
        ]));
    }

    public function testReleaseRejectsPayloadMissingReservationId(): void
    {
        $client = static::createClient();
        $handler = $client->getContainer()->get(\App\Store\MessageHandler\ReservationReleasedHandler::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid inventory.reservation.released.v1 envelope.');
        $handler(new ReservationReleasedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000H4',
            'type' => 'inventory.reservation.released',
            'version' => 1,
            'payload' => ['releasedAt' => '2026-07-26T00:00:00+00:00'],
        ]));
    }

    public function testReleaseConsumesEventWithNonUuidReservationId(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $handler = $container->get(\App\Store\MessageHandler\ReservationReleasedHandler::class);

        $handler(new ReservationReleasedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000H5',
            'type' => 'inventory.reservation.released',
            'version' => 1,
            'payload' => ['reservationId' => 'plain-reservation-id', 'releasedAt' => '2026-07-26T00:00:00+00:00'],
        ]));

        $consumed = $container->get(StoreConsumedEventRepository::class)->findOneByEventId('00000000-0000-4000-8000-0000000000H5');
        self::assertNotNull($consumed);
        self::assertSame('inventory.reservation.released.v1', $consumed->getTopic());
        self::assertSame('plain-reservation-id', $consumed->getAggregateId());
    }

    /** @return array{\Symfony\Component\DependencyInjection\ContainerInterface, StoreOrder} */
    private function awaitingOrder(): array
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $store = new Store('outcome-cov', 'Outcome Cov', 'UTC');
        $order = new StoreOrder($store, '00000000-0000-4000-8000-0000000000H6', 'outcome-cov', 'Outcome Cov', null, 'CNY', 100, ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-26T00:00:00+00:00']);
        $order->awaitInventory('00000000-0000-4000-8000-0000000000H7');
        $em->persist($store);
        $em->persist($order);
        $em->flush();

        return [$container, $order];
    }

    /** @param array<string, string> $extra @return array<string, string> */
    private function outcomePayload(StoreOrder $order, array $extra): array
    {
        return array_merge([
            'reservationId' => $order->getReservationId(),
            'storeUuid' => $order->getStore()->getUuid(),
            'tradeOrderUuid' => $order->getTradeOrderUuid(),
            'storeOrderUuid' => $order->getUuid(),
        ], $extra);
    }
}
