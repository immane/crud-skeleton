<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\EventListener;

use App\Store\EventListener\StoreOrderWorkflowGuardListener;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Repository\OrderStoreLifecycleRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

final class StoreOrderWorkflowGuardListenerTest extends TestCase
{
    public function testCompleteWaitsForStoreFulfillmentFact(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_FULFILLED)->setMetadata(['_store' => ['uuid' => 'store-uuid']]);
        $repository = $this->createMock(OrderStoreLifecycleRepository::class);
        $repository->expects(self::once())->method('findOneByTradeOrderUuid')->with($order->getUuid())->willReturn(
            new OrderStoreLifecycle($order->getUuid(), 'store-uuid'),
        );
        $event = new GuardEvent($order, new Marking([Order::STATUS_FULFILLED => 1]), new Transition('complete', Order::STATUS_FULFILLED, Order::STATUS_COMPLETED));

        (new StoreOrderWorkflowGuardListener($repository))->onGuard($event);

        self::assertTrue($event->isBlocked());
        $blockers = iterator_to_array($event->getTransitionBlockerList());
        self::assertSame('Store fulfillment required before complete.', $blockers[0]->getMessage());
    }

    public function testConfirmPassesWithoutWaitingWhenAcceptanceIsNotRequired(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PENDING)->setMetadata(['_store' => ['uuid' => 'store-uuid', 'requireAcceptance' => false]]);
        $repository = $this->createMock(OrderStoreLifecycleRepository::class);
        $repository->expects(self::never())->method('findOneByTradeOrderUuid');
        $event = new GuardEvent($order, new Marking([Order::STATUS_PENDING => 1]), new Transition('confirm', Order::STATUS_PENDING, Order::STATUS_CONFIRMED));

        (new StoreOrderWorkflowGuardListener($repository))->onGuard($event);

        self::assertFalse($event->isBlocked());
    }

    public function testConfirmKeepsLegacyAcceptanceRequirementWhenPolicyIsMissing(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PENDING)->setMetadata(['_store' => ['uuid' => 'store-uuid']]);
        $repository = $this->createMock(OrderStoreLifecycleRepository::class);
        $repository->expects(self::once())->method('findOneByTradeOrderUuid')->with($order->getUuid())->willReturn(
            new OrderStoreLifecycle($order->getUuid(), 'store-uuid'),
        );
        $event = new GuardEvent($order, new Marking([Order::STATUS_PENDING => 1]), new Transition('confirm', Order::STATUS_PENDING, Order::STATUS_CONFIRMED));

        (new StoreOrderWorkflowGuardListener($repository))->onGuard($event);

        self::assertTrue($event->isBlocked());
    }

    public function testConfirmWaitsForAcceptanceFactWhenAcceptanceIsRequired(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PENDING)->setMetadata(['_store' => ['uuid' => 'store-uuid', 'requireAcceptance' => true]]);
        $repository = $this->createMock(OrderStoreLifecycleRepository::class);
        $repository->expects(self::once())->method('findOneByTradeOrderUuid')->with($order->getUuid())->willReturn(
            new OrderStoreLifecycle($order->getUuid(), 'store-uuid'),
        );
        $event = new GuardEvent($order, new Marking([Order::STATUS_PENDING => 1]), new Transition('confirm', Order::STATUS_PENDING, Order::STATUS_CONFIRMED));

        (new StoreOrderWorkflowGuardListener($repository))->onGuard($event);

        self::assertTrue($event->isBlocked());
        $blockers = iterator_to_array($event->getTransitionBlockerList());
        self::assertSame('Store acceptance required before confirm.', $blockers[0]->getMessage());
    }
}
