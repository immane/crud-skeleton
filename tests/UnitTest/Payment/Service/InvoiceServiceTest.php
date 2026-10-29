<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Payment\Service;

use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Exception\InvoiceInvalidTransitionException;
use App\Payment\Service\Adjustment\PaymentAdjustmentProviderInterface;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use App\Payment\Service\Gateway\MockGateway;
use App\Payment\Service\InvoiceService;
use App\Payment\Service\InvoiceSourcePolicyInterface;
use App\Payment\Service\PaymentGatewayRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\WorkflowInterface;

#[AllowMockObjectsWithoutExpectations]
final class InvoiceServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EntityRepository $repository;
    private PaymentAdjustmentRegistry $adjustmentRegistry;
    private PaymentGatewayRegistry $gatewayRegistry;
    private WorkflowInterface $workflow;
    private EventDispatcherInterface $dispatcher;
    private InvoiceService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('isTransactionActive')->willReturn(false);
        $this->em->method('getConnection')->willReturn($this->connection);

        $this->repository = $this->createMock(EntityRepository::class);
        $this->em->method('getRepository')->willReturn($this->repository);

        $this->gatewayRegistry = new PaymentGatewayRegistry([new MockGateway()]);
        $this->adjustmentRegistry = new PaymentAdjustmentRegistry([]);
        $this->workflow = $this->createMock(WorkflowInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->service = $this->createService($this->adjustmentRegistry);
    }

    /** @param list<InvoiceSourcePolicyInterface> $sourcePolicies */
    private function createService(PaymentAdjustmentRegistry $adjustments, array $sourcePolicies = []): InvoiceService
    {
        $container = new Container();
        $container->set('doctrine.orm.entity_manager', $this->em);
        $container->set('logger', new NullLogger());

        return new InvoiceService(
            $container,
            $this->gatewayRegistry,
            $adjustments,
            $this->workflow,
            $this->dispatcher,
            $sourcePolicies,
        );
    }

    public function testCreateInvoiceRejectsNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice amount cannot be negative.');

        $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src', 'order', -1));
    }

    #[Group('low-value')]
    public function testPayThrowsWhenStartPayTransitionNotAllowed(): void
    {
        $invoice = (new Invoice())->setAmount(100);
        $this->workflow->method('can')->with($invoice, 'start_pay')->willReturn(false);

        $this->expectException(InvoiceInvalidTransitionException::class);
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
    }

    public function testPayThrowsWhenDeductionExceedsInvoiceAmount(): void
    {
        $service = $this->createService(new PaymentAdjustmentRegistry([new FakeAdjustmentProvider(200)]));
        $invoice = (new Invoice())->setAmount(100);
        $this->workflow->method('can')->with($invoice, 'start_pay')->willReturn(true);

        $this->expectException(InvoiceAmountMismatchException::class);
        $this->expectExceptionMessage('Deduction amount exceeds invoice amount.');

        $service->pay($invoice, Invoice::PAYMENT_MOCK, ['walletAmount' => 200]);
    }

    public function testPayAppliesGatewayAndTradeTypeOptions(): void
    {
        $invoice = (new Invoice())->setAmount(1000);
        $this->workflow->method('can')->with($invoice, 'start_pay')->willReturn(true);

        $result = $this->service->pay($invoice, Invoice::PAYMENT_MOCK, [
            'gateway' => 'online',
            'tradeType' => 'h5',
        ]);

        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame(Invoice::PAYMENT_MOCK, $invoice->getPayment());
        self::assertSame('online', $invoice->getGateway());
        self::assertSame('h5', $invoice->getTradeType());
        self::assertSame(1000, $invoice->getExtraData()['pay']['amount']);
    }

    public function testCustomSourceLocksBeforeInvoiceRefreshAndSynchronousCompletionDoesNotRefreshAgain(): void
    {
        $policy = new class implements InvoiceSourcePolicyInterface {
            public int $locks = 0;
            public static function getSourceType(): string { return 'custom_document'; }
            public function lock(Invoice $invoice): void { ++$this->locks; }
            public function assertRefundable(Invoice $invoice): void { throw new \LogicException('Not used.'); }
        };
        $invoice = (new Invoice())->setSourceType('custom_document')->setAmount(100);
        $this->em->expects(self::once())->method('refresh')->with($invoice, self::anything())
            ->willReturnCallback(static function () use ($policy): void {
                self::assertSame(1, $policy->locks, 'The source lock must precede the invoice lock.');
            });
        $this->workflow->method('can')->willReturn(true);
        $this->workflow->method('apply')->willReturnCallback(static function (Invoice $subject, string $transition): \Symfony\Component\Workflow\Marking {
            $subject->setStatus($transition === 'start_pay' ? Invoice::STATUS_PAYING : Invoice::STATUS_PAID);
            return new \Symfony\Component\Workflow\Marking([$subject->getStatus() => 1]);
        });
        $this->dispatcher->expects(self::once())->method('dispatch')->willReturnArgument(0);

        $result = $this->createService($this->adjustmentRegistry, [$policy])->pay($invoice, Invoice::PAYMENT_MOCK, ['autoPaid' => true]);

        self::assertSame(Invoice::STATUS_PAID, $result->status);
        self::assertSame(1, $policy->locks);
        self::assertSame(100, $invoice->getExtraData()['pay']['amount']);
        self::assertSame($invoice->getExtraData()['pay'], $invoice->getExtraData()['notify']);
    }

    #[Group('low-value')]
    public function testHandleNotifyResultThrowsWhenInvoiceNotFound(): void
    {
        $this->repository->method('findOneBy')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invoice PAY-NOT-FOUND not found.');

        $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: 'PAY-NOT-FOUND',
            status: Invoice::STATUS_PAID,
            amount: 100,
        ));
    }

    #[Group('low-value')]
    public function testMarkPaidThrowsWhenWorkflowCannotMarkPaid(): void
    {
        $invoice = (new Invoice())
            ->setStatus(Invoice::STATUS_PAYING)
            ->setAmount(100);
        $this->workflow->method('can')->with($invoice, 'mark_paid')->willReturn(false);

        $this->expectException(InvoiceInvalidTransitionException::class);

        $this->service->markPaid($invoice, new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_PAID,
            amount: 100,
            currency: 'CNY',
        ));
    }

    public function testMarkFailedThrowsWhenWorkflowCannotFail(): void
    {
        $invoice = (new Invoice())->setStatus(Invoice::STATUS_PENDING);
        $this->workflow->method('can')->with($invoice, 'fail')->willReturn(false);

        $this->expectException(InvoiceInvalidTransitionException::class);

        $this->service->markFailed($invoice, new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_FAILED,
            amount: 100,
        ));
    }

    #[Group('low-value')]
    public function testCancelThrowsWhenWorkflowCannotCancel(): void
    {
        $invoice = new Invoice();
        $this->workflow->method('can')->with($invoice, 'cancel')->willReturn(false);

        $this->expectException(InvoiceInvalidTransitionException::class);

        $this->service->cancel($invoice);
    }

    #[Group('low-value')]
    public function testRefundThrowsWhenWorkflowCannotApplyTransition(): void
    {
        $invoice = (new Invoice())
            ->setAmount(100)
            ->setStatus(Invoice::STATUS_PAID)
            ->setPayment(Invoice::PAYMENT_MOCK);
        $this->workflow->method('can')->with($invoice, 'partial_refund')->willReturn(false);

        $this->expectException(InvoiceInvalidTransitionException::class);

        $this->service->refund($invoice, 50, 'partial refund');
    }
}

final class FakeAdjustmentProvider implements PaymentAdjustmentProviderInterface
{
    public function __construct(private readonly int $amount = 200)
    {
    }

    public static function getName(): string
    {
        return 'fake_balance';
    }

    public function supports(Invoice $invoice, string $payment, array $options): bool
    {
        return true;
    }

    public function apply(PaymentAdjustmentContext $context): PaymentAdjustmentResult
    {
        return new PaymentAdjustmentResult(
            self::getName(),
            $this->amount,
            $context->currency,
            'ref-' . $context->invoice->getUuid(),
        );
    }

    /** @return PaymentAdjustmentResult[] */
    public function applied(Invoice $invoice): array
    {
        return [
            new PaymentAdjustmentResult(self::getName(), $this->amount, $invoice->getCurrency(), 'ref-' . $invoice->getUuid()),
        ];
    }

    public function release(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        return $adjustment;
    }

    public function refund(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult
    {
        return $adjustment;
    }
}
