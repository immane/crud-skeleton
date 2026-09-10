<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use PHPUnit\Framework\TestCase;

final class StoreOrderTest extends TestCase
{
    public function testSnapshotIsInitializedAndOperationalStatusChanges(): void
    {
        $order = new StoreOrder(
            new Store('demo', 'Demo', 'Asia/Shanghai'),
            '2beed699-4e1b-4a49-af75-2e0b0f6db0fd',
            'demo',
            'Demo',
            '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57',
            'cny',
            12800,
            ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-24T12:00:00+00:00'],
        );

        self::assertSame(StoreOrder::STATUS_PENDING_VALIDATION, $order->getOperationalStatus());
        self::assertSame('CNY', $order->getCurrency());
        self::assertSame(12800, $order->getTotalAmount());

        $order->awaitInventory('reservation-1')->accept();
        self::assertSame(StoreOrder::STATUS_ACCEPTED, $order->getOperationalStatus());
        self::assertSame('reservation-1', $order->getReservationId());
        self::assertInstanceOf(\DateTimeImmutable::class, $order->getAcceptedAt());
    }
}
