<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\DTO;

use App\Store\DTO\StoreSettings;
use PHPUnit\Framework\TestCase;

final class StoreSettingsTest extends TestCase
{
    public function testDefaultsAreAllFalse(): void
    {
        $settings = StoreSettings::from(null);

        self::assertFalse($settings->requireAcceptance);
        self::assertFalse($settings->requireInventory);
        self::assertFalse($settings->requireVerification);
    }

    public function testOrderFlagsAreParsed(): void
    {
        $settings = StoreSettings::from(['order' => ['requireAcceptance' => true, 'requireInventory' => true]]);

        self::assertTrue($settings->requireAcceptance);
        self::assertTrue($settings->requireInventory);
        self::assertFalse($settings->requireVerification);
        self::assertSame(
            ['order' => ['requireAcceptance' => true, 'requireInventory' => true], 'fulfillment' => ['requireVerification' => false]],
            $settings->toArray(),
        );
    }

    public function testNonBooleanAcceptanceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('settings.order.requireAcceptance must be a boolean.');

        StoreSettings::from(['order' => ['requireAcceptance' => 'yes']]);
    }

    public function testNonBooleanInventoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('settings.order.requireInventory must be a boolean.');

        StoreSettings::from(['order' => ['requireInventory' => 1]]);
    }

    public function testNonObjectOrderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('settings.order must be an object or null.');

        StoreSettings::from(['order' => 'strict']);
    }
}
