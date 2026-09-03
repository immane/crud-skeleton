<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Service;

use App\Identity\Entity\User;
use App\Trade\Entity\Order;
use App\Trade\Service\Catalog\CatalogItem;
use App\Trade\Service\Catalog\CatalogResolverInterface;
use App\Trade\Service\OrderService;
use App\Trade\Service\Pricing\BasePriceCalculator;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\QuantityCalculator;
use App\Trade\Service\Pricing\TotalAggregator;
// removed
use App\Wallet\Entity\Transaction;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Transfer\TransferResult;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    private function createService(
        array $calculators,
        ?WalletRepository $walletRepository = null,
        ?TransferServiceInterface $transferService = null,
    ): OrderService
    {
        $reflection = new \ReflectionClass(OrderService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('priceCalculators');
        $prop->setValue($service, $calculators);

        $walletRepositoryProp = $reflection->getProperty('walletRepository');
        $walletRepositoryProp->setValue($service, $walletRepository);

        $transferServiceProp = $reflection->getProperty('transferService');
        $transferServiceProp->setValue($service, $transferService);

        return $service;
    }

    public function testCalculatePricesDelegatesToPipeline(): void
    {
        $catalogItem = new CatalogItem(
            id: 1,
            uuid: '00000000-0000-0000-0000-000000000001',
            name: 'Red',
            price: 500,
            status: 'active',
            isDeleted: false,
            productId: 10,
            productUuid: '00000000-0000-0000-0000-000000000010',
            productName: 'Phone',
            productIsDeleted: false,
            productStatus: 'active',
        );
        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn($catalogItem);

        $calculators = [
            new BasePriceCalculator($resolver),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        $service = $this->createService($calculators);

        $result = $service->calculatePrices([
            ['specificationId' => 1, 'quantity' => 3],
        ]);

        self::assertInstanceOf(PriceCalculationResult::class, $result);
        self::assertSame(1500, $result->totalAmount);
        self::assertCount(1, $result->items);
        self::assertSame(500, $result->items[0]['unitPrice']);
        self::assertSame(3, $result->items[0]['quantity']);
        self::assertSame(1500, $result->items[0]['price']);
    }

    public function testCalculatePricesWithCustomCurrency(): void
    {
        $catalogItem = new CatalogItem(
            id: 1,
            uuid: '00000000-0000-0000-0000-000000000002',
            name: 'Spec',
            price: 100,
            status: 'active',
            isDeleted: false,
            productId: 10,
            productUuid: '00000000-0000-0000-0000-000000000011',
            productName: 'Product',
            productIsDeleted: false,
            productStatus: 'active',
        );
        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn($catalogItem);

        $calculators = [
            new BasePriceCalculator($resolver),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        $service = $this->createService($calculators);

        $result = $service->calculatePrices([
            ['specificationId' => 1, 'quantity' => 1],
        ], 'CNY');

        self::assertSame('CNY', $result->currency);
    }

    public function testCalculatePricesWithEmptyItems(): void
    {
        $service = $this->createService([]);

        $result = $service->calculatePrices([]);

        self::assertInstanceOf(PriceCalculationResult::class, $result);
        self::assertSame(0, $result->totalAmount);
        self::assertSame([], $result->items);
    }

    #[DataProvider('pricingCalculationsProvider')]
    public function testPricingCalculations(int $unitPrice, int $quantity, int $expectedTotal): void
    {
        $catalogItem = new CatalogItem(
            id: 1,
            uuid: '00000000-0000-0000-0000-000000000003',
            name: 'Spec',
            price: $unitPrice,
            status: 'active',
            isDeleted: false,
            productId: 10,
            productUuid: '00000000-0000-0000-0000-000000000012',
            productName: 'Product',
            productIsDeleted: false,
            productStatus: 'active',
        );
        $resolver = $this->createMock(CatalogResolverInterface::class);
        $resolver->method('resolveForPricing')->willReturn($catalogItem);

        $calculators = [
            new BasePriceCalculator($resolver),
            new QuantityCalculator(),
            new TotalAggregator(),
        ];

        $service = $this->createService($calculators);

        $result = $service->calculatePrices([
            ['specificationId' => 1, 'quantity' => $quantity],
        ]);

        self::assertSame($expectedTotal, $result->totalAmount);
    }

    public static function pricingCalculationsProvider(): array
    {
        return [
            'zero price' => [0, 10, 0],
            'single item' => [100, 1, 100],
            'multiple items' => [500, 3, 1500],
            'large quantity' => [100, 100, 10000],
            'large price' => [999999, 1, 999999],
        ];
    }

    public function testPayRejectsNonConfirmedOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_DRAFT);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be in "confirmed" status to pay');

        $service->pay($order, 9);
    }

    public function testPayRequiresWalletModule(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet module is not configured');

        $service->pay($order, 9);
    }

    public function testPayRequiresOrderUser(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $service = $this->createService(
            [],
            $this->createMock(WalletRepository::class),
            $this->createMock(TransferServiceInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order has no associated user');

        $service->pay($order, 9);
    }

    public function testPayRequiresUserWallet(): void
    {
        $user = $this->createUser(42);
        $order = (new Order())
            ->setUser($user)
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setCurrency('CNY');

        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->expects(self::once())
            ->method('findByUserAndCurrency')
            ->with(42, 'CNY')
            ->willReturn(null);

        $service = $this->createService(
            [],
            $walletRepository,
            $this->createMock(TransferServiceInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No CNY wallet found for user #42');

        $service->pay($order, 9);
    }

    public function testPayTransfersFromUserWalletAndMarksPayment(): void
    {
        $user = $this->createUser(42);
        $wallet = $this->createWallet($user, 7);
        $order = (new Order())
            ->setUser($user)
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setTotalAmount(1234)
            ->setCurrency('CNY');

        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->expects(self::once())
            ->method('findByUserAndCurrency')
            ->with(42, 'CNY')
            ->willReturn($wallet);

        $transferService = $this->createMock(TransferServiceInterface::class);
        $transferService->expects(self::once())
            ->method('transfer')
            ->with(7, 9, 1234, 'manual-pay-ref', 'Payment for order #0')
            ->willReturn(new TransferResult(new Transaction('order-pay-tx', 1234, Transaction::TYPE_TRANSFER), 0, 0));

        $service = $this->createService([], $walletRepository, $transferService);

        $service->pay($order, 9, 'wallet', 'manual-pay-ref');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getPaidAt());
        self::assertSame('wallet', $order->getPaymentMethod());
    }

    public function testRefundRejectsNonCompletedOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_COMPLETED);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be in "paid" status to refund');

        $service->refund($order, 9, 'duplicate');
    }

    public function testRefundTransfersToUserWalletAndMarksRefund(): void
    {
        $user = $this->createUser(42);
        $wallet = $this->createWallet($user, 7);
        $order = (new Order())
            ->setUser($user)
            ->setStatus(Order::STATUS_PAID)
            ->setTotalAmount(1234)
            ->setCurrency('CNY');

        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->method('findByUserAndCurrency')->willReturn($wallet);

        $transferService = $this->createMock(TransferServiceInterface::class);
        $transferService->expects(self::once())
            ->method('transfer')
            ->with(9, 7, 1234, 'manual-refund-ref', 'Refund for order #0: duplicate')
            ->willReturn(new TransferResult(new Transaction('order-refund-tx', 1234, Transaction::TYPE_TRANSFER), 0, 0));

        $service = $this->createService([], $walletRepository, $transferService);

        $service->refund($order, 9, 'duplicate', 'manual-refund-ref');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getRefundedAt());
        self::assertSame('duplicate', $order->getRefundReason());
    }

    public function testFulfillRejectsNonPaidOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be in "paid" status to fulfill');

        $service->fulfill($order, []);
    }

    public function testFulfillStoresShippingData(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID);
        $service = $this->createService([]);

        $service->fulfill($order, [
            'trackingNumber' => 'TRACK-1',
            'shippingAddress' => 'Shanghai',
        ]);

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getFulfilledAt());
        self::assertSame('TRACK-1', $order->getTrackingNumber());
        self::assertSame('Shanghai', $order->getShippingAddress());
    }

    private function createUser(int $id): User
    {
        $user = new User();
        $user->setUsername('user' . $id);
        $user->setEmail('user' . $id . '@example.com');
        $user->setPassword('hashed');

        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setValue($user, $id);

        return $user;
    }

    private function createWallet(User $user, int $id): Wallet
    {
        $wallet = new Wallet($user, 'CNY');

        $idProperty = new \ReflectionProperty(Wallet::class, 'id');
        $idProperty->setValue($wallet, $id);

        return $wallet;
    }
}
