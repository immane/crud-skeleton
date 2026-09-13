<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\MessageHandler;

use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Message\StoreOrderAcceptedMessage;
use App\Trade\MessageHandler\StoreOrderAcceptedHandler;
use App\Trade\Repository\TradeConsumedEventRepository;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\OrderStoreLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\WorkflowInterface;

final class StoreOrderAcceptedHandlerTest extends TestCase
{
    private const STORE_UUID = '00000000-0000-4000-8000-000000000099';
    private const ORDER_UUID = '00000000-0000-4000-8000-000000000098';

    public function testRejectsMissingEnvelopePayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid store.order.accepted.v1 envelope.');

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage([]));
    }

    public function testRejectsNonArrayPayload(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => 'not-an-array']));
    }

    public function testRejectsMissingOrderUuid(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);

        $this->expectException(\InvalidArgumentException::class);

        (new StoreOrderAcceptedHandler($orders, $workflow))(new StoreOrderAcceptedMessage(['payload' => ['storeUuid' => self::STORE_UUID]]));
    }

    public function testMarksAcceptedViaLifecycleService(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);
        $lifecycle = $this->createMock(OrderStoreLifecycleService::class);
        $lifecycle->expects(self::once())->method('markAccepted')->with(self::ORDER_UUID, self::STORE_UUID, 'store-order-uuid')->willReturn(new OrderStoreLifecycle(self::ORDER_UUID, self::STORE_UUID));

        $handler = new StoreOrderAcceptedHandler($orders, $workflow, null, $lifecycle, null);
        $handler(new StoreOrderAcceptedMessage(['payload' => [
            'orderUuid' => self::ORDER_UUID,
            'storeUuid' => self::STORE_UUID,
            'storeOrderUuid' => 'store-order-uuid',
        ]]));
    }

    public function testIsIdempotentViaConsumedEvent(): void
    {
        $orders = $this->createStub(OrderServiceInterface::class);
        $workflow = $this->createStub(WorkflowInterface::class);
        $repo = $this->createMock(TradeConsumedEventRepository::class);
        $repo->method('findOneByEventId')->with('event-123')->willReturn(new \App\Trade\Entity\TradeConsumedEvent('event-123', 'store.order.accepted.v1', self::ORDER_UUID, 'hash'));
        $lifecycle = $this->createMock(OrderStoreLifecycleService::class);
        $lifecycle->expects(self::never())->method('markAccepted');

        $handler = new StoreOrderAcceptedHandler($orders, $workflow, $repo, $lifecycle, $this->createStub(EntityManagerInterface::class));
        $handler(new StoreOrderAcceptedMessage(['eventId' => 'event-123', 'payload' => [
            'orderUuid' => self::ORDER_UUID,
            'storeUuid' => self::STORE_UUID,
        ]]));
    }
}
