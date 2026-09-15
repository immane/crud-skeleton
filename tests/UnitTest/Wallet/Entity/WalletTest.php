<?php

namespace App\Tests\UnitTest\Wallet\Entity;

use App\Identity\Entity\User;
use App\Wallet\Entity\Wallet;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class WalletTest extends TestCase
{
    public function testConstructorInitializesDefaults(): void
    {
        $user = new User();
        $wallet = new Wallet($user);

        self::assertSame($user, $wallet->getUser());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $wallet->getUuid());
        self::assertSame('USD', $wallet->getCurrency());
        self::assertSame(0, $wallet->getBalance());
        self::assertSame(0.0, $wallet->getBalanceAsFloat());
        self::assertSame(1, $wallet->getVersion());
        self::assertSame('active', $wallet->getStatus());
        self::assertTrue($wallet->isActive());
        self::assertFalse($wallet->isFrozen());
        self::assertNull($wallet->getLabel());
        self::assertInstanceOf(\DateTimeImmutable::class, $wallet->getCreatedAt());
        self::assertNull($wallet->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testSetUser(): void
    {
        $user1 = new User();
        $user1->setUsername('user1');
        $user2 = new User();
        $user2->setUsername('user2');

        $wallet = new Wallet($user1);
        self::assertSame($user1, $wallet->getUser());

        $wallet->setUser($user2);
        self::assertSame($user2, $wallet->getUser());

        $wallet->setUser(null);
        self::assertNull($wallet->getUser());
    }

    #[Group('low-value')]
    public function testConstructorCurrencyUppercase(): void
    {
        $wallet = new Wallet(new User(), 'eur');
        self::assertSame('EUR', $wallet->getCurrency());
    }

    public function testSetCurrency(): void
    {
        $wallet = new Wallet(new User(), 'USD');
        $wallet->setCurrency('eur');
        self::assertSame('EUR', $wallet->getCurrency());

        $wallet->setCurrency('btc');
        self::assertSame('BTC', $wallet->getCurrency());
    }

    public function testSetCurrencyThrowsAfterPersistence(): void
    {
        $wallet = new Wallet(new User(), 'CNY');
        $rId = new \ReflectionProperty(Wallet::class, 'id');
        $rId->setValue($wallet, 7);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Wallet currency is immutable after creation.');
        $wallet->setCurrency('USD');
    }

    public function testSetStatus(): void
    {
        $wallet = new Wallet(new User());
        $wallet->setStatus('frozen');
        self::assertSame('frozen', $wallet->getStatus());
        self::assertTrue($wallet->isFrozen());
        self::assertFalse($wallet->isActive());
    }

    #[Group('low-value')]
    public function testSetLabel(): void
    {
        $wallet = new Wallet(new User());
        $wallet->setLabel('My savings');
        self::assertSame('My savings', $wallet->getLabel());

        $wallet->setLabel(null);
        self::assertNull($wallet->getLabel());
    }

    public function testTouchUpdatesTimestamp(): void
    {
        $wallet = new Wallet(new User());
        self::assertNull($wallet->getUpdatedAt());

        $wallet->touch();
        self::assertInstanceOf(\DateTimeImmutable::class, $wallet->getUpdatedAt());
    }

    #[Group('low-value')]
    public function testPrePersistWhenCreatedFromReflection(): void
    {
        $reflection = new \ReflectionClass(Wallet::class);
        $wallet = $reflection->newInstanceWithoutConstructor();

        $wallet->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $wallet->getCreatedAt());
    }

    #[Group('low-value')]
    public function testPrePersistKeepsExistingCreatedAt(): void
    {
        $wallet = new Wallet(new User());
        $createdAt = $wallet->getCreatedAt();

        $wallet->prePersist();
        self::assertSame($createdAt, $wallet->getCreatedAt());
    }

    public function testToString(): void
    {
        $user = new User();
        $user->setUsername('johndoe');
        $wallet = new Wallet($user, 'USD');

        $str = (string) $wallet;
        self::assertStringContainsString('johndoe', $str);
        self::assertStringContainsString('USD', $str);
        self::assertStringContainsString('0.00', $str);
    }

    public function testBalanceAsFloatWithCents(): void
    {
        // Bigint stores cents: 10050 = $100.50
        $user = new User();
        $wallet = new Wallet($user);

        $ref = new \ReflectionProperty(Wallet::class, 'balance');
        $ref->setValue($wallet, 10050);

        self::assertSame(10050, $wallet->getBalance());
        self::assertSame(100.50, $wallet->getBalanceAsFloat());
    }

    #[Group('low-value')]
    public function testBalanceBoundaryZero(): void
    {
        $wallet = new Wallet(new User());
        self::assertSame(0, $wallet->getBalance());
        self::assertSame(0.0, $wallet->getBalanceAsFloat());
    }

    #[Group('low-value')]
    public function testBalanceBoundaryLarge(): void
    {
        $wallet = new Wallet(new User());
        $ref = new \ReflectionProperty(Wallet::class, 'balance');
        $largeValue = 999999999999; // ~$10 billion in cents
        $ref->setValue($wallet, $largeValue);

        self::assertSame($largeValue, $wallet->getBalance());
        self::assertSame(9999999999.99, $wallet->getBalanceAsFloat());
    }
}
