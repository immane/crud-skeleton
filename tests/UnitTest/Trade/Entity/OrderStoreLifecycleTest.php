<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Entity;

use App\Trade\Entity\OrderStoreLifecycle;
use PHPUnit\Framework\TestCase;

final class OrderStoreLifecycleTest extends TestCase
{
    public function testLateFulfilledEventDoesNotUndoVerification(): void
    {
        $lifecycle = new OrderStoreLifecycle('order-uuid', 'store-uuid', 'store-order-uuid');

        $lifecycle->markVerified('store-order-uuid');
        $lifecycle->markFulfilled('store-order-uuid', true);

        self::assertSame(OrderStoreLifecycle::FULFILLMENT_FULFILLED, $lifecycle->getFulfillmentStatus());
        self::assertSame(OrderStoreLifecycle::VERIFICATION_VERIFIED, $lifecycle->getVerificationStatus());
    }
}
