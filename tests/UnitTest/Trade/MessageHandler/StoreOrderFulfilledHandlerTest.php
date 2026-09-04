<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\MessageHandler;

use App\Trade\Message\StoreOrderFulfilledMessage;
use App\Trade\MessageHandler\StoreOrderFulfilledHandler;
use App\Trade\Service\OrderStoreLifecycleService;
use PHPUnit\Framework\TestCase;

final class StoreOrderFulfilledHandlerTest extends TestCase
{
    public function testRejectsFulfilledEventWithoutVerificationPolicySnapshot(): void
    {
        $handler = new StoreOrderFulfilledHandler(null, $this->createStub(OrderStoreLifecycleService::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid store.order.fulfilled.v1 envelope.');
        $handler(new StoreOrderFulfilledMessage(['payload' => [
            'orderUuid' => 'order-uuid',
            'storeUuid' => 'store-uuid',
        ]]));
    }

    public function testProjectsVerificationPolicySnapshot(): void
    {
        $lifecycle = $this->createMock(OrderStoreLifecycleService::class);
        $lifecycle->expects(self::once())->method('markFulfilled')->with('order-uuid', 'store-uuid', 'store-order-uuid', true);
        $handler = new StoreOrderFulfilledHandler(null, $lifecycle);

        $handler(new StoreOrderFulfilledMessage(['payload' => [
            'orderUuid' => 'order-uuid',
            'storeUuid' => 'store-uuid',
            'storeOrderUuid' => 'store-order-uuid',
            'requiresVerification' => true,
        ]]));
    }
}
