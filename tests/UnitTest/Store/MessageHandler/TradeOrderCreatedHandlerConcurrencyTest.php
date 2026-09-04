<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\MessageHandler;

use App\Store\Entity\StoreConsumedEvent;
use App\Store\MessageHandler\TradeOrderCreatedHandler;
use App\Store\Repository\StoreConsumedEventRepository;
use App\Store\Repository\StoreRepository;
use App\Store\Repository\StoreTradeOrderCancellationRepository;
use App\Store\Service\StoreOrderServiceInterface;
use App\Store\Service\StoreOutboxService;
use App\Trade\Message\TradeOrderCreatedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class TradeOrderCreatedHandlerConcurrencyTest extends TestCase
{
    public function testReturnsWhenEventIsConsumedInsideTheTransaction(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );

        $consumedEvent = new StoreConsumedEvent('00000000-0000-4000-8000-0000000000P1', 'trade.order.created.v1', '00000000-0000-4000-8000-0000000000P2', 'payload-hash');
        $consumedRepository = $this->createMock(StoreConsumedEventRepository::class);
        $calls = 0;
        $consumedRepository->method('findOneBy')->willReturnCallback(
            static function () use (&$calls, $consumedEvent): ?StoreConsumedEvent {
                return ++$calls > 1 ? $consumedEvent : null;
            },
        );

        $storeRepository = $this->createMock(StoreRepository::class);
        $cancellationRepository = new StoreTradeOrderCancellationRepository($this->createMock(ManagerRegistry::class));
        $storeOrderService = $this->createMock(StoreOrderServiceInterface::class);

        $entityManager->expects(self::never())->method('persist');

        $handler = new TradeOrderCreatedHandler(
            $storeRepository,
            $consumedRepository,
            $cancellationRepository,
            $storeOrderService,
            new StoreOutboxService($entityManager),
            $entityManager,
            false,
        );
        $handler(new TradeOrderCreatedMessage([
            'eventId' => '00000000-0000-4000-8000-0000000000P1',
            'payload' => [
                'orderUuid' => '00000000-0000-4000-8000-0000000000P2',
                'store' => ['uuid' => '00000000-0000-4000-8000-0000000000P3', 'code' => 'demo', 'name' => 'Demo'],
                'currency' => 'CNY',
                'totalAmount' => 100,
                'items' => [],
                'delivery' => [],
                'placedAt' => '2026-07-26T00:00:00+00:00',
            ],
        ]));

        self::assertSame(2, $calls);
    }
}
