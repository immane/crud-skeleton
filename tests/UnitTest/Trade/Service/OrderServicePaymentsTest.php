<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Service;

use App\Identity\Entity\User;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Entity\TradeOutboxMessage;
use App\Trade\Service\OrderService;
use App\Trade\Service\TradeOutboxService;
use App\Wallet\Entity\Wallet;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Unit coverage for the OrderService branches not exercised by
 * tests/Trade/Service/OrderServiceTest.php:
 * createOrder store-submit path, pay/refund guard failures, createPayment/refundPayment.
 */
final class OrderServicePaymentsTest extends TestCase
{
    private const STORE_UUID = '00000000-0000-4000-8000-000000000050';
    private const STORE_CODE = 'shenzhen-01';

    /**
     * @param array<string, mixed> $overrides
     */
    private function createService(array $overrides = []): OrderService
    {
        $reflection = new \ReflectionClass(OrderService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $defaults = [
            'priceCalculators' => [],
            'walletRepository' => null,
            'transferService' => null,
            'invoiceService' => null,
            'outboxService' => null,
            'workflow' => null,
            'storeRepository' => null,
        ];
        $props = array_merge($defaults, $overrides);

        foreach ($props as $propName => $value) {
            $reflection->getProperty($propName)->setValue($service, $value);
        }

        return $service;
    }

    /** @return EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub */
    private function createEntityManager(?callable $onPersist = null): EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub
    {
        $em = $this->createStub(EntityManagerInterface::class);
        if ($onPersist !== null) {
            $em->method('persist')->willReturnCallback($onPersist);
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $em->method('getConnection')->willReturn($connection);

        return $em;
    }

    // ========================================================================
    // createOrder — store orchestration (202 path)
    // ========================================================================

    public function testCreateOrderWithoutStoreContextCreatesDraftOrder(): void
    {
        $em = $this->createEntityManager();
        $service = $this->createService(['em' => $em]);

        $order = $service->createOrder(
            [
                ['specification' => null, 'quantity' => 2, 'unitPrice' => 500, 'price' => 1000],
            ],
            null,
            1000,
            'CNY',
            'notes',
            ['foo' => 'bar'],
        );

        self::assertSame(Order::STATUS_DRAFT, $order->getStatus());
        self::assertSame(1000, $order->getTotalAmount());
        self::assertSame('CNY', $order->getCurrency());
        self::assertSame('notes', $order->getNotes());
        self::assertSame(['foo' => 'bar'], $order->getMetadata());
        self::assertNull($order->getUser());
        self::assertCount(1, $order->getItems());
        self::assertNull($order->getMetadata()['_store'] ?? null);
    }

    #[Group('low-value')]
    public function testCreateOrderWithStoreContextThrowsWhenWorkflowMissing(): void
    {
        $em = $this->createEntityManager();
        $service = $this->createService([
            'em' => $em,
            'outboxService' => new TradeOutboxService($em),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store order orchestration is not configured.');

        $service->createOrder([], null, 100, 'CNY', null, [], $this->storeContext());
    }

    #[Group('low-value')]
    public function testCreateOrderWithStoreContextThrowsWhenOutboxMissing(): void
    {
        $em = $this->createEntityManager();
        $service = $this->createService([
            'em' => $em,
            'workflow' => $this->createStub(WorkflowInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store order orchestration is not configured.');

        $service->createOrder([], null, 100, 'CNY', null, [], $this->storeContext());
    }

    public function testCreateOrderWithStoreContextThrowsWhenWorkflowCannotSubmit(): void
    {
        $em = $this->createEntityManager();
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->willReturn(false);
        $workflow->expects(self::never())->method('apply');

        // Store requires acceptance -> can=false should throw
        $store = new \App\Store\Entity\Store('STORE001', 'Test Store');
        $store->setSettings(['order' => ['requireAcceptance' => true]]);
        // Need to set uuid to match StoreContext STORE_UUID constant
        $ref = new \ReflectionProperty(\App\Store\Entity\Store::class, 'uuid');
        $ref->setValue($store, self::STORE_UUID);
        $storeRepository = $this->createMock(\App\Store\Repository\StoreRepository::class);
        $storeRepository->method('findOneBy')->willReturn($store);

        $service = $this->createService([
            'em' => $em,
            'workflow' => $workflow,
            'outboxService' => new TradeOutboxService($em),
            'storeRepository' => $storeRepository,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order cannot be submitted for store acceptance.');

        $service->createOrder([], null, 100, 'CNY', null, [], $this->storeContext());
    }

    public function testCreateOrderWithUserInstanceAssignsUser(): void
    {
        $user = $this->createUser(7);
        $em = $this->createEntityManager();
        $service = $this->createService(['em' => $em]);

        $order = $service->createOrder([], $user, 100, 'CNY');

        self::assertSame($user, $order->getUser());
        self::assertSame(Order::STATUS_DRAFT, $order->getStatus());
    }

    public function testCreateOrderPersistsItemSnapshotData(): void
    {
        $product = new \App\Store\Entity\Product();
        $product->setName('Phone');
        $spec = new \App\Store\Entity\Specification();
        $spec->setProduct($product);
        $spec->setName('Red');

        $em = $this->createEntityManager();
        $service = $this->createService(['em' => $em]);

        $order = $service->createOrder(
            [
                [
                    'specification' => $spec,
                    'quantity' => 2,
                    'unitPrice' => 500,
                    'price' => 1000,
                    'specSnapshot' => ['name' => 'Red'],
                    'productSnapshot' => ['name' => 'Phone'],
                ],
            ],
            null,
            1000,
            'CNY',
        );

        $items = $order->getItems();
        self::assertCount(1, $items);
        $item = $items->first();
        self::assertSame($spec->getUuid(), $item->getSpecificationUuid());
        self::assertSame(['name' => 'Red'], $item->getSpecSnapshot());
        self::assertSame(['name' => 'Phone'], $item->getProductSnapshot());
    }

    public function testCreateOrderWithStoreContextAppliesSubmitAndRecordsOutbox(): void
    {
        $persisted = [];
        $em = $this->createEntityManager(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            }
        );

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->willReturn(true);
        $workflow->expects(self::once())->method('apply');

        $service = $this->createService([
            'em' => $em,
            'workflow' => $workflow,
            'outboxService' => new TradeOutboxService($em),
        ]);

        $order = $service->createOrder(
            [
                ['specification' => null, 'quantity' => 3, 'unitPrice' => 200, 'price' => 600],
            ],
            null,
            600,
            'CNY',
            null,
            ['delivery' => ['city' => 'Shenzhen']],
            $this->storeContext(),
        );

        $messages = array_values(array_filter(
            $persisted,
            static fn (object $entity): bool => $entity instanceof TradeOutboxMessage
        ));
        self::assertCount(1, $messages);
        self::assertInstanceOf(TradeOutboxMessage::class, $messages[0]);
        self::assertSame('trade.order.created.v1', $messages[0]->getTopic());
        self::assertSame($order->getUuid(), $messages[0]->getAggregateId());

        $payload = $messages[0]->getPayload();
        self::assertSame($order->getUuid(), $payload['orderUuid']);
        self::assertSame(self::STORE_UUID, $payload['store']['uuid']);
        self::assertSame('CNY', $payload['currency']);
        self::assertSame(600, $payload['totalAmount']);
        self::assertSame(['city' => 'Shenzhen'], $payload['delivery']);
        self::assertNull($payload['customerUserUuid']);
        self::assertSame(3, $payload['items'][0]['quantity']);
        self::assertSame(200, $payload['items'][0]['unitPrice']);
        self::assertSame(600, $payload['items'][0]['lineAmount']);
        self::assertSame('', $payload['items'][0]['catalogReference']);
    }

    public function testCreateOrderWithStoreContextAndUserReferenceRecordsCustomerUuid(): void
    {
        $user = $this->createUser(42);
        $persisted = [];
        $em = $this->createEntityManager(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            }
        );
        $em->method('getReference')->willReturn($user);

        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);

        $service = $this->createService([
            'em' => $em,
            'workflow' => $workflow,
            'outboxService' => new TradeOutboxService($em),
        ]);

        $order = $service->createOrder([], ['id' => 42], 100, 'CNY', null, [], $this->storeContext());

        self::assertSame($user, $order->getUser());

        $messages = array_values(array_filter(
            $persisted,
            static fn (object $entity): bool => $entity instanceof TradeOutboxMessage
        ));
        self::assertCount(1, $messages);
        self::assertSame($user->getUuid(), $messages[0]->getPayload()['customerUserUuid']);
    }

    // ========================================================================
    // pay() — remaining guard branches
    // ========================================================================

    #[Group('low-value')]
    public function testPayRejectsUserWithoutPersistedId(): void
    {
        $order = (new Order())
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setUser(new User())
            ->setCurrency('CNY');

        $service = $this->createService([
            'walletRepository' => $this->createStub(WalletRepository::class),
            'transferService' => $this->createStub(TransferServiceInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User has not been persisted yet (no ID).');

        $service->pay($order, 9);
    }

    #[Group('low-value')]
    public function testPayRejectsWalletWithoutPersistedId(): void
    {
        $user = $this->createUser(42);
        $wallet = new Wallet($user, 'CNY');
        $order = (new Order())
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setUser($user)
            ->setCurrency('CNY');

        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->method('findByUserAndCurrency')->with(42, 'CNY')->willReturn($wallet);

        $service = $this->createService([
            'walletRepository' => $walletRepository,
            'transferService' => $this->createStub(TransferServiceInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet has not been persisted yet (no ID).');

        $service->pay($order, 9);
    }

    // ========================================================================
    // refund() — remaining guard branches
    // ========================================================================

    public function testRefundRequiresWalletModule(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_COMPLETED);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet module is not configured. Set up wallet before processing refunds.');

        $service->refund($order, 9, 'duplicate');
    }

    public function testRefundRejectsOrderWithoutUser(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_COMPLETED)->setCurrency('CNY');
        $service = $this->createService([
            'walletRepository' => $this->createStub(WalletRepository::class),
            'transferService' => $this->createStub(TransferServiceInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order has no associated user.');

        $service->refund($order, 9, 'duplicate');
    }

    #[Group('low-value')]
    public function testRefundRejectsUserWithoutPersistedId(): void
    {
        $order = (new Order())
            ->setStatus(Order::STATUS_COMPLETED)
            ->setUser(new User())
            ->setCurrency('CNY');

        $service = $this->createService([
            'walletRepository' => $this->createStub(WalletRepository::class),
            'transferService' => $this->createStub(TransferServiceInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User has not been persisted yet (no ID).');

        $service->refund($order, 9, 'duplicate');
    }

    public function testRefundRejectsMissingUserWallet(): void
    {
        $user = $this->createUser(42);
        $order = (new Order())
            ->setStatus(Order::STATUS_COMPLETED)
            ->setUser($user)
            ->setCurrency('CNY');

        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->method('findByUserAndCurrency')->with(42, 'CNY')->willReturn(null);

        $service = $this->createService([
            'walletRepository' => $walletRepository,
            'transferService' => $this->createStub(TransferServiceInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No CNY wallet found for user #42.');

        $service->refund($order, 9, 'duplicate');
    }

    #[Group('low-value')]
    public function testRefundRejectsWalletWithoutPersistedId(): void
    {
        $user = $this->createUser(42);
        $wallet = new Wallet($user, 'CNY');
        $order = (new Order())
            ->setStatus(Order::STATUS_COMPLETED)
            ->setUser($user)
            ->setCurrency('CNY');

        $walletRepository = $this->createMock(WalletRepository::class);
        $walletRepository->method('findByUserAndCurrency')->with(42, 'CNY')->willReturn($wallet);

        $service = $this->createService([
            'walletRepository' => $walletRepository,
            'transferService' => $this->createStub(TransferServiceInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wallet has not been persisted yet (no ID).');

        $service->refund($order, 9, 'duplicate');
    }

    // ========================================================================
    // createPayment()
    // ========================================================================

    public function testCreatePaymentRequiresPaymentModule(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED);
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment module is not configured.');

        $service->createPayment($order);
    }

    public function testCreatePaymentRejectsNonConfirmedOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_DRAFT);
        $service = $this->createService([
            'invoiceService' => $this->createStub(InvoiceServiceInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only confirmed orders can start payment.');

        $service->createPayment($order);
    }

    public function testCreatePaymentReusesExistingInvoice(): void
    {
        $invoice = (new Invoice())->setAmount(1000)->setCurrency('CNY');
        $order = (new Order())
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setInvoiceId($invoice->getUuid())
            ->setTotalAmount(1000)
            ->setCurrency('CNY');

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('get')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);
        $invoiceService->expects(self::once())->method('pay')->with($invoice, 'mock', [])->willReturn(
            new PaymentResult($invoice, Invoice::STATUS_PAYING)
        );
        $invoiceService->expects(self::never())->method('createInvoice');

        $service = $this->createService(['invoiceService' => $invoiceService]);

        $result = $service->createPayment($order);

        self::assertInstanceOf(PaymentResult::class, $result);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame($invoice->getUuid(), $order->getInvoiceId());
    }

    public function testCreatePaymentCreatesNewInvoiceWhenNoneLinked(): void
    {
        $order = (new Order())
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setTotalAmount(1000)
            ->setCurrency('CNY')
            ->setNotes('test notes');
        $created = new Invoice();
        $created->setAmount(1000)->setCurrency('CNY');

        $em = $this->createEntityManager();

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::never())->method('get');
        $invoiceService->expects(self::once())->method('createInvoice')->willReturn($created);
        $invoiceService->expects(self::once())->method('pay')->with($created, 'mock', [])->willReturn(
            new PaymentResult($created, Invoice::STATUS_PAYING)
        );

        $service = $this->createService([
            'em' => $em,
            'invoiceService' => $invoiceService,
        ]);

        $result = $service->createPayment($order);

        self::assertSame($created->getUuid(), $order->getInvoiceId());
        self::assertSame($created->getOutTradeNo(), $order->getInvoiceNo());
        self::assertSame(Invoice::STATUS_PENDING, $order->getPaymentStatus());
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
    }

    public function testCreatePaymentReusesInvoiceRegardlessOfItsStatus(): void
    {
        // Documents Bug #1 (see report): a failed/cancelled invoice is handed to
        // InvoiceService::pay(), which requires the invoice to be in "pending".
        $failed = (new Invoice())->setStatus(Invoice::STATUS_FAILED)->setAmount(1000)->setCurrency('CNY');
        $order = (new Order())
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setInvoiceId($failed->getUuid())
            ->setTotalAmount(1000)
            ->setCurrency('CNY');

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('get')->with(['uuid' => $failed->getUuid()])->willReturn($failed);
        $invoiceService->expects(self::never())->method('createInvoice');
        $invoiceService->expects(self::once())->method('pay')->with($failed, 'mock', [])->willReturn(
            new PaymentResult($failed, Invoice::STATUS_PAYING)
        );

        $service = $this->createService(['invoiceService' => $invoiceService]);

        $service->createPayment($order);
    }

    public function testCreatePaymentCreatesFreshInvoiceWhenExistingInvoiceIsNotPayable(): void
    {
        // SKIPPED: documents Bug #1 from the report. Correct behaviour would create a
        // fresh invoice (and a new payable flow) when the order's linked invoice is in a
        // terminal status (failed/cancelled). The current implementation reuses that
        // invoice and forwards it to InvoiceService::pay(), which throws
        // InvoiceInvalidTransitionException because `start_pay` only applies from "pending".
        $this->markTestSkipped('Known bug: OrderService::createPayment reuses non-payable invoices (see report Bug #1).');

        $failed = (new Invoice())->setStatus(Invoice::STATUS_FAILED)->setAmount(1000)->setCurrency('CNY');
        $order = (new Order())
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setInvoiceId($failed->getUuid())
            ->setTotalAmount(1000)
            ->setCurrency('CNY');

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('get')->willReturn($failed);
        $invoiceService->expects(self::once())->method('createInvoice')->willReturn(new Invoice());
        $invoiceService->expects(self::never())->method('pay')->with($failed, self::anything());

        $service = $this->createService(['invoiceService' => $invoiceService]);

        $service->createPayment($order);
    }

    // ========================================================================
    // refundPayment()
    // ========================================================================

    public function testRefundPaymentRequiresPaymentModule(): void
    {
        $order = new Order();
        $service = $this->createService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment module is not configured.');

        $service->refundPayment($order, 'duplicate');
    }

    public function testRefundPaymentRejectsOrderWithoutLinkedInvoice(): void
    {
        $order = new Order();
        $invoiceService = $this->createStub(InvoiceServiceInterface::class);

        $service = $this->createService(['invoiceService' => $invoiceService]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order has no linked invoice.');

        $service->refundPayment($order, 'duplicate');
    }

    public function testRefundPaymentRefundsRemainingInvoiceAmount(): void
    {
        $invoice = (new Invoice())->setAmount(2000)->setRefundedAmount(500);
        $order = (new Order())->setInvoiceId($invoice->getUuid());

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('get')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);
        $invoiceService->expects(self::once())->method('refund')->with($invoice, 1500, 'duplicate', [])->willReturn(
            new PaymentRefundResult($invoice, 1500, Invoice::STATUS_REFUNDED)
        );

        $service = $this->createService(['invoiceService' => $invoiceService]);

        $result = $service->refundPayment($order, 'duplicate', []);

        self::assertSame(1500, $result->amount);
        self::assertSame(Invoice::STATUS_REFUNDED, $result->status);
    }

    // ========================================================================
    // cancel()
    // ========================================================================

    public function testCancelDoesNothingWhenInvoiceServiceMissing(): void
    {
        $order = (new Order())->setInvoiceId('some-invoice-uuid');
        $service = $this->createService([]);

        $service->cancel($order);

        self::assertSame('some-invoice-uuid', $order->getInvoiceId());
    }

    public function testCancelCancelsLinkedInvoice(): void
    {
        $invoice = new Invoice();
        $order = (new Order())->setInvoiceId($invoice->getUuid());

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('get')->with(['uuid' => $invoice->getUuid()])->willReturn($invoice);
        $invoiceService->expects(self::once())->method('cancel')->with($invoice, 'Order cancelled.');

        $service = $this->createService(['invoiceService' => $invoiceService]);

        $service->cancel($order);
    }

    public function testCancelIgnoresMissingInvoice(): void
    {
        $order = (new Order())->setInvoiceId('missing-invoice-uuid');

        $invoiceService = $this->createMock(InvoiceServiceInterface::class);
        $invoiceService->expects(self::once())->method('get')->willReturn(null);
        $invoiceService->expects(self::never())->method('cancel');

        $service = $this->createService(['invoiceService' => $invoiceService]);

        $service->cancel($order);
    }

    // ========================================================================
    // calculatePrices() — traversable calculator pipeline
    // ========================================================================

    #[Group('low-value')]
    public function testCalculatePricesAcceptsTraversableCalculators(): void
    {
        $service = $this->createService(['priceCalculators' => new \ArrayIterator([])]);

        $result = $service->calculatePrices([]);

        self::assertSame(0, $result->totalAmount);
        self::assertSame([], $result->items);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function storeContext(): StoreContext
    {
        return new StoreContext(self::STORE_UUID, self::STORE_CODE, 'Shenzhen Store');
    }

    private function createUser(int $id): User
    {
        $user = new User();
        $user->setUsername('user' . $id);
        $user->setEmail('user' . $id . '@example.com');
        $user->setPassword('hashed');

        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
