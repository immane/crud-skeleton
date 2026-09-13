<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Service;

use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Repository\OrderStoreLifecycleRepository;
use App\Trade\Service\OrderStoreLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class OrderStoreLifecycleServiceTest extends TestCase
{
    public function testRejectsEventForAnotherStore(): void
    {
        $lifecycle = new OrderStoreLifecycle('order-uuid', 'store-a', 'store-order-a');
        $repository = $this->createMock(OrderStoreLifecycleRepository::class);
        $repository->expects(self::once())->method('findOneByTradeOrderUuid')->with('order-uuid')->willReturn($lifecycle);
        $service = new OrderStoreLifecycleService($repository, $this->createStub(EntityManagerInterface::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not belong to the order store');
        $service->markAccepted('order-uuid', 'store-b', 'store-order-b');
    }

    public function testRejectsEventForAnotherStoreOrder(): void
    {
        $lifecycle = new OrderStoreLifecycle('order-uuid', 'store-a', 'store-order-a');
        $repository = $this->createMock(OrderStoreLifecycleRepository::class);
        $repository->expects(self::once())->method('findOneByTradeOrderUuid')->with('order-uuid')->willReturn($lifecycle);
        $service = new OrderStoreLifecycleService($repository, $this->createStub(EntityManagerInterface::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not belong to the Store order');
        $service->markAccepted('order-uuid', 'store-a', 'store-order-b');
    }
}
