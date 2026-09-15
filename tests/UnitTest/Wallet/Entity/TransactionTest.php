<?php

namespace App\Tests\UnitTest\Wallet\Entity;

use App\Wallet\Entity\Transaction;
use App\Wallet\Entity\Wallet;
use App\Identity\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    public function testConstructorInitializesDefaults(): void
    {
        $tx = new Transaction('uuid-123', 5000, Transaction::TYPE_TRANSFER);

        self::assertSame('uuid-123', $tx->getUuid());
        self::assertSame(5000, $tx->getAmount());
        self::assertSame(50.00, $tx->getAmountAsFloat());
        self::assertSame(Transaction::TYPE_TRANSFER, $tx->getType());
        self::assertSame(Transaction::STATUS_PENDING, $tx->getStatus());
        self::assertNull($tx->getFromWallet());
        self::assertNull($tx->getToWallet());
        self::assertNull($tx->getReferenceId());
        self::assertNull($tx->getDescription());
        self::assertNull($tx->getMetadata());
        self::assertNull($tx->getCompletedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $tx->getCreatedAt());
    }

    public function testConstructorGeneratesUuidWhenNoneIsProvided(): void
    {
        $tx = new Transaction(null, 5000, Transaction::TYPE_TRANSFER);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $tx->getUuid());
    }

    public function testConstructorInvalidTypeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Transaction('uid', 100, 'invalid_type');
    }

    public function testAllValidTypes(): void
    {
        foreach (['deposit', 'withdrawal', 'transfer', 'fee', 'refund'] as $type) {
            $tx = new Transaction('uid-' . $type, 100, $type);
            self::assertSame($type, $tx->getType());
        }
    }

    #[Group('low-value')]
    public function testSetFromAndToWallet(): void
    {
        $user = new User();
        $from = new Wallet($user);
        $to = new Wallet($user, 'EUR');

        $tx = new Transaction('uid', 100, 'transfer');
        $tx->setFromWallet($from);
        $tx->setToWallet($to);

        self::assertSame($from, $tx->getFromWallet());
        self::assertSame($to, $tx->getToWallet());
    }

    #[Group('low-value')]
    public function testSetReferenceId(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');
        $tx->setReferenceId('ref-001');
        self::assertSame('ref-001', $tx->getReferenceId());

        $tx->setReferenceId(null);
        self::assertNull($tx->getReferenceId());
    }

    #[Group('low-value')]
    public function testSetDescription(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');
        $tx->setDescription('Transfer to friend');
        self::assertSame('Transfer to friend', $tx->getDescription());
    }

    #[Group('low-value')]
    public function testSetMetadata(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');
        $tx->setMetadata('{"ip":"1.2.3.4"}');
        self::assertSame('{"ip":"1.2.3.4"}', $tx->getMetadata());
    }

    public function testSetInvalidStatus(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');

        $this->expectException(\InvalidArgumentException::class);
        $tx->setStatus('unknown_status');
    }

    public function testAllValidStatuses(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');

        foreach (['pending', 'completed', 'failed', 'reversed'] as $status) {
            $tx->setStatus($status);
            self::assertSame($status, $tx->getStatus());
        }
    }

    public function testMarkCompleted(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');
        $tx->markCompleted();

        self::assertSame(Transaction::STATUS_COMPLETED, $tx->getStatus());
        self::assertTrue($tx->isCompleted());
        self::assertInstanceOf(\DateTimeImmutable::class, $tx->getCompletedAt());
    }

    public function testMarkFailed(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');
        $tx->markFailed();

        self::assertSame(Transaction::STATUS_FAILED, $tx->getStatus());
        self::assertFalse($tx->isCompleted());
    }

    #[Group('low-value')]
    public function testPrePersist(): void
    {
        $reflection = new \ReflectionClass(Transaction::class);
        $tx = $reflection->newInstanceWithoutConstructor();

        $tx->prePersist();
        self::assertInstanceOf(\DateTimeImmutable::class, $tx->getCreatedAt());
    }

    #[Group('low-value')]
    public function testPrePersistKeepsExisting(): void
    {
        $tx = new Transaction('uid', 100, 'transfer');
        $createdAt = $tx->getCreatedAt();

        $tx->prePersist();
        self::assertSame($createdAt, $tx->getCreatedAt());
    }

    public function testAmountBoundaryOneCent(): void
    {
        $tx = new Transaction('uid', 1, 'transfer');
        self::assertSame(1, $tx->getAmount());
        self::assertSame(0.01, $tx->getAmountAsFloat());
    }

    #[Group('low-value')]
    public function testAmountBoundaryLarge(): void
    {
        $tx = new Transaction('uid', 999999999999, 'transfer');
        self::assertSame(999999999999, $tx->getAmount());
        self::assertSame(9999999999.99, $tx->getAmountAsFloat());
    }

    #[Group('low-value')]
    public function testUuidPersistenceAcrossOperations(): void
    {
        $tx = new Transaction('original-uuid', 100, 'transfer');
        $tx->markCompleted();

        self::assertSame('original-uuid', $tx->getUuid(), 'UUID should not change after markCompleted');
    }

    public function testToString(): void
    {
        $tx = new Transaction('test-uuid', 10000, 'transfer');
        $str = (string) $tx;

        self::assertStringContainsString('transfer', $str);
        self::assertStringContainsString('test-uuid', $str);
        self::assertStringContainsString('100.00', $str);
    }
}
