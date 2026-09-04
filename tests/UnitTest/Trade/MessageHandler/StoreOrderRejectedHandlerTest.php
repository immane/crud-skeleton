<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Message\StoreOrderRejectedMessage;
use App\Trade\MessageHandler\StoreOrderRejectedHandler;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\OrderStoreLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\WorkflowInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreOrderRejectedHandlerTest extends TestCase
{
    private const STORE_UUID = '00000000-0000-4000-8000-000000000040';
    private const ORDER_UUID = '00000000-0000-4000-8000-000000000041';

    public function testRejectsMissingEnvelopePayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid store.order.rejected.v1 envelope.');

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage([]));
    }

    public function testRejectsNonArrayPayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);

        (new StoreOrderRejectedHandler($orders, $workflow))(new StoreOrderRejectedMessage(['payload' => 42]));
    }

    public function testMarksRejectedViaLifecycleService(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);
        $lifecycle = $this->createMock(OrderStoreLifecycleService::class);
        $lifecycle->expects(self::once())->method('markRejected')->with(self::ORDER_UUID, self::STORE_UUID, 'store-order-uuid', 'OUT_OF_STOCK', 'no stock')->willReturn(new OrderStoreLifecycle(self::ORDER_UUID, self::STORE_UUID));
        // Order lookup for auto-cancel is not needed if lifecycle handles it, but handler will try to fetch order for auto-cancel; stub to return null to avoid cancel.
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->method('get')->willReturn(null);

        $handler = new StoreOrderRejectedHandler($orders, $workflow, null, $lifecycle, null);
        $handler(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => self::ORDER_UUID,
            'storeUuid' => self::STORE_UUID,
            'storeOrderUuid' => 'store-order-uuid',
            'reasonCode' => 'OUT_OF_STOCK',
            'reason' => 'no stock',
        ]]));
    }

    public function testAutoCancelsPendingOrderOnRejection(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PENDING)->setMetadata(['_store' => ['uuid' => self::STORE_UUID]]);
        // set uuid via reflection to match payload
        $ref = new \ReflectionProperty(Order::class, 'uuid');
        $ref->setValue($order, self::ORDER_UUID);
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->method('get')->with(['uuid' => self::ORDER_UUID])->willReturn($order);
        $orders->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb): mixed => $cb());
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'cancel')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($order, 'cancel');
        $lifecycle = $this->createMock(OrderStoreLifecycleService::class);
        $lifecycle->method('markRejected')->willReturn(new OrderStoreLifecycle(self::ORDER_UUID, self::STORE_UUID));

        $handler = new StoreOrderRejectedHandler($orders, $workflow, null, $lifecycle, null);
        $handler(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => self::ORDER_UUID,
            'storeUuid' => self::STORE_UUID,
            'storeOrderUuid' => 'store-order-uuid',
            'reasonCode' => 'OUT_OF_STOCK',
            'reason' => 'no stock',
        ]]));
    }

    public function testDoesNotAutoCancelWhenAlreadyPaid(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID)->setMetadata(['_store' => ['uuid' => self::STORE_UUID]]);
        $ref = new \ReflectionProperty(Order::class, 'uuid');
        $ref->setValue($order, self::ORDER_UUID);
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->method('get')->willReturn($order);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('can');
        $workflow->expects(self::never())->method('apply');
        $lifecycle = $this->createMock(OrderStoreLifecycleService::class);
        $lifecycle->method('markRejected')->willReturn(new OrderStoreLifecycle(self::ORDER_UUID, self::STORE_UUID));

        $handler = new StoreOrderRejectedHandler($orders, $workflow, null, $lifecycle, null);
        $handler(new StoreOrderRejectedMessage(['payload' => [
            'orderUuid' => self::ORDER_UUID,
            'storeUuid' => self::STORE_UUID,
        ]]));
    }
}
