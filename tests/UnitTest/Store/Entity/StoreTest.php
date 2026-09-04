<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Entity;

use App\Store\Entity\Store;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    public function testLifecycleAndMutableStoreDetails(): void
    {
        $store = new Store('shanghai-demo', 'Demo Store', 'Asia/Shanghai');

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $store->getUuid());
        self::assertSame('shanghai-demo', $store->getCode());
        self::assertTrue($store->isActive());
        self::assertNull($store->getUpdatedAt());

        $store->setName('Demo Flagship')->suspend();
        self::assertSame(Store::STATUS_SUSPENDED, $store->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $store->getUpdatedAt());

        $store->close();
        self::assertSame(Store::STATUS_CLOSED, $store->getStatus());
    }

    public function testContactAddressAndSettingsSettersTouchUpdatedAt(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');

        $store->setContact(['phone' => '021-12345678']);
        $store->setAddress(['city' => 'Shanghai']);
        $store->setSettings(['acceptingOrders' => true]);

        self::assertSame(['phone' => '021-12345678'], $store->getContact());
        self::assertSame(['city' => 'Shanghai'], $store->getAddress());
        self::assertSame(['acceptingOrders' => true], $store->getSettings());
        self::assertInstanceOf(\DateTimeImmutable::class, $store->getUpdatedAt());

        $store->setContact(null)->setAddress(null)->setSettings(null);
        self::assertNull($store->getContact());
        self::assertNull($store->getAddress());
        self::assertNull($store->getSettings());
    }

    public function testActivateRestoresActiveStatus(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $store->suspend();
        self::assertFalse($store->isActive());

        $store->activate();

        self::assertTrue($store->isActive());
        self::assertSame(Store::STATUS_ACTIVE, $store->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $store->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testCodeAndTimezoneAreMutable(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');

        $store->setCode('demo-flagship')->setTimezone('Asia/Shanghai');

        self::assertSame('demo-flagship', $store->getCode());
        self::assertSame('Demo', $store->getName());
        self::assertSame('Asia/Shanghai', $store->getTimezone());
        self::assertNull($store->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $store->getCreatedAt());
    }

    public function testStringRepresentationPrefersNameOverCode(): void
    {
        $withName = new Store('demo', 'Demo Store', 'Asia/Shanghai');
        self::assertSame('Demo Store', (string) $withName);

        $withoutName = new Store('demo');
        self::assertSame('demo', (string) $withoutName);
    }
}
