<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentAdjustmentContext;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Entity\InvoiceRefundAttempt;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Repository\InvoiceRepository;
use App\Payment\Service\InvoiceServiceInterface;
use App\Payment\Service\InvoiceService;
use App\Payment\Service\Adjustment\PaymentAdjustmentProviderInterface;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use App\Payment\Service\PaymentGatewayRegistry;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class InvoiceServiceIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private InvoiceServiceInterface $service;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(InvoiceServiceInterface::class);
    }

    public function testCreatePayNotifyRefundAndFindBySource(): void
    {
        $payer = $this->createUser('payer@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest(
            sourceType: 'manual',
            sourceId: 'order-1',
            scene: Invoice::SCENE_ORDER,
            amount: 1200,
            currency: 'CNY',
            payer: $payer,
            subject: 'Order 1',
            description: 'Test invoice',
            extraData: ['secret' => 'must-redact'],
        ));

        self::assertNotNull($invoice->getId());
        self::assertSame(Invoice::STATUS_PENDING, $invoice->getStatus());
        self::assertSame([$invoice], $this->service->findBySource('manual', 'order-1'));
        $repository = static::getContainer()->get(InvoiceRepository::class);
        self::assertSame($invoice, $repository->findOneByOutTradeNo($invoice->getOutTradeNo()));
        self::assertSame([$invoice], $repository->findBySource('manual', 'order-1'));

        $result = $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        self::assertSame(Invoice::STATUS_PAYING, $result->status);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());

        $paid = $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_PAID,
            amount: 1200,
            currency: 'CNY',
            transactionId: 'txn-service',
            rawData: ['signature' => 'secret-signature', 'ok' => true],
        ));
        self::assertSame(Invoice::STATUS_PAID, $paid->getStatus());
        self::assertSame('txn-service', $paid->getTransactionId());
        self::assertSame('[redacted]', $paid->getExtraData()['notify']['signature']);

        $duplicate = $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_PAID,
            amount: 1200,
        ));
        self::assertSame($paid->getId(), $duplicate->getId());

        $partial = $this->service->refund($invoice, 500, 'partial');
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $partial->status);
        self::assertSame(1200, $partial->rawData['gateway']['paidAmount']);
        self::assertSame(500, $invoice->getRefundedAmount());
        $attempts = $this->em->getRepository(InvoiceRefundAttempt::class)->findBy(['invoiceUuid' => $invoice->getUuid()]);
        self::assertCount(1, $attempts);
        self::assertSame(InvoiceRefundAttempt::STATUS_COMPLETED, $attempts[0]->getStatus());

        $duplicatePartial = $this->service->refund($invoice, 500, 'partial');
        self::assertSame($partial->refundId, $duplicatePartial->refundId);
        self::assertSame(500, $invoice->getRefundedAmount());

        $full = $this->service->refund($invoice, 700, 'full');
        self::assertSame(Invoice::STATUS_REFUNDED, $full->status);
        self::assertSame(1200, $invoice->getRefundedAmount());
        self::assertNotNull($invoice->getRefundedAt());
    }

    public function testCancelFailedNotifyAndValidationBranches(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-2', Invoice::SCENE_DEPOSIT, 100));
        $this->service->cancel($invoice, 'cancelled');
        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());
        self::assertSame('cancelled', $invoice->getExtraData()['cancel']['reason']);

        $failed = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-3', Invoice::SCENE_DEPOSIT, 100));
        $this->service->markFailed($failed, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $failed->getOutTradeNo(), Invoice::STATUS_FAILED, 100));
        self::assertSame(Invoice::STATUS_FAILED, $failed->getStatus());

        $mismatch = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-4', Invoice::SCENE_DEPOSIT, 100));
        $this->expectException(InvoiceAmountMismatchException::class);
        $this->service->markPaid($mismatch, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $mismatch->getOutTradeNo(), Invoice::STATUS_PAID, 99));
    }

    public function testGatewayRegistryFromContainer(): void
    {
        $registry = static::getContainer()->get(PaymentGatewayRegistry::class);
        self::assertTrue($registry->has(Invoice::PAYMENT_MOCK));
        self::assertTrue($registry->has(Invoice::PAYMENT_WALLET));
        self::assertTrue($registry->has(Invoice::PAYMENT_WECHAT));
        self::assertContains(Invoice::PAYMENT_MOCK, $registry->names());
        self::assertContains(Invoice::PAYMENT_WECHAT, $registry->names());
    }

    public function testSynchronousPaymentPreservesGatewayContextAndPayloadAfterReload(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'sync-context', Invoice::SCENE_ORDER, 100,
            extraData: ['original' => ['reference' => 'keep-original']]));
        $gateway = new class implements \App\Payment\Service\PaymentGatewayInterface {
            public static function getName(): string { return 'context'; }
            public function pay(Invoice $invoice, int $amount, array $options = []): \App\Payment\DTO\PaymentResult {
                $invoice->appendExtraData('gateway_context', ['profileUuid' => 'original-profile']);
                return new \App\Payment\DTO\PaymentResult($invoice, Invoice::STATUS_PAID, payload: [
                    'transactionId' => 'sync-transaction', 'reference' => 'keep-pay', 'token' => 'test-token',
                ]);
            }
            public function notify(\Symfony\Component\HttpFoundation\Request $request): PaymentNotifyResult { throw new \LogicException('Not used.'); }
            public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): \App\Payment\DTO\PaymentRefundResult { throw new \LogicException('Not used.'); }
            public function getNotifySuccessResponse(PaymentNotifyResult $result): \Symfony\Component\HttpFoundation\Response { throw new \LogicException('Not used.'); }
        };
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('doctrine.orm.entity_manager', $this->em);
        $service = new InvoiceService($container, new PaymentGatewayRegistry([$gateway]), new PaymentAdjustmentRegistry([]),
            static::getContainer()->get('state_machine.invoice'), static::getContainer()->get('event_dispatcher'));

        $result = $service->pay($invoice, 'context');
        self::assertSame(Invoice::STATUS_PAID, $result->status);
        $id = $invoice->getId();
        $this->em->clear();
        $reloaded = $this->em->find(Invoice::class, $id);
        self::assertSame(Invoice::STATUS_PAID, $reloaded->getStatus());
        self::assertSame('sync-transaction', $reloaded->getTransactionId());
        self::assertSame(['reference' => 'keep-original'], $reloaded->getExtraData()['original']);
        self::assertSame(['profileUuid' => 'original-profile'], $reloaded->getExtraData()['gateway_context']);
        self::assertSame('keep-pay', $reloaded->getExtraData()['pay']['reference']);
        self::assertSame('[redacted]', $reloaded->getExtraData()['pay']['token']);
        self::assertSame($reloaded->getExtraData()['pay'], $reloaded->getExtraData()['notify']);
    }

    public function testCustomSourcePolicyGuardsRefundAndAllowsCompletedReplayWithoutTrade(): void
    {
        $policy = new class($this->em) implements \App\Payment\Service\InvoiceSourcePolicyInterface {
            public bool $allowRefund = false;
            public int $locks = 0;
            public int $refundChecks = 0;
            public function __construct(private EntityManagerInterface $em) {}
            public static function getSourceType(): string { return 'custom_document'; }
            public function lock(Invoice $invoice): void {
                if (!$this->em->getConnection()->isTransactionActive()) {
                    throw new \LogicException('Source locks require the invoice transaction.');
                }
                ++$this->locks;
            }
            public function assertRefundable(Invoice $invoice): void {
                ++$this->refundChecks;
                if (!$this->allowRefund) {
                    throw new \DomainException('Source document does not allow refunds.');
                }
            }
        };
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('doctrine.orm.entity_manager', $this->em);
        $service = new InvoiceService($container, new PaymentGatewayRegistry([new \App\Payment\Service\Gateway\MockGateway()]),
            new PaymentAdjustmentRegistry([]), static::getContainer()->get('state_machine.invoice'), static::getContainer()->get('event_dispatcher'), [$policy]);
        $invoice = $service->createInvoice(new CreateInvoiceRequest('custom_document', 'custom-1', Invoice::SCENE_ORDER, 100));
        $service->pay($invoice, Invoice::PAYMENT_MOCK, ['autoPaid' => true]);
        self::assertSame(1, $policy->locks);
        try {
            $service->refund($invoice, 100, 'custom refund');
            self::fail('Source policy must guard refunds before a reservation is persisted.');
        } catch (\DomainException $exception) {
            self::assertSame('Source document does not allow refunds.', $exception->getMessage());
        }
        self::assertCount(0, $this->em->getRepository(InvoiceRefundAttempt::class)->findBy(['invoiceUuid' => $invoice->getUuid()]));
        $policy->allowRefund = true;
        $result = $service->refund($invoice, 100, 'custom refund');
        self::assertSame(Invoice::STATUS_REFUNDED, $result->status);
        self::assertSame(2, $policy->refundChecks);
        $policy->allowRefund = false;
        $replay = $service->refund($invoice, 100, 'custom refund');
        self::assertSame($result->refundId, $replay->refundId);
        self::assertSame(2, $policy->refundChecks);
        self::assertSame(100, $invoice->getRefundedAmount());

        $retryInvoice = $service->createInvoice(new CreateInvoiceRequest('custom_document', 'custom-retry', Invoice::SCENE_ORDER, 100));
        $service->pay($retryInvoice, Invoice::PAYMENT_MOCK, ['autoPaid' => true]);
        $key = 'custom-retry';
        $attempt = new InvoiceRefundAttempt(hash('sha256', $retryInvoice->getUuid()."\0".$key), $retryInvoice->getUuid(), 40, 'retry', [
            'hasAdjustment' => false, 'gatewayAmount' => 40, 'gatewayPaidAmount' => 100,
            'expectedRefundedAmount' => 0, 'payment' => Invoice::PAYMENT_MOCK,
        ]);
        $attempt->markUnknown();
        $this->em->persist($attempt);
        $this->em->flush();
        try {
            $service->refund($retryInvoice, 40, 'retry', ['idempotencyKey' => $key]);
            self::fail('Resumed refunds must also pass source eligibility validation.');
        } catch (\DomainException $exception) {
            self::assertSame('Source document does not allow refunds.', $exception->getMessage());
        }
        $this->em->refresh($attempt);
        self::assertSame(InvoiceRefundAttempt::STATUS_UNKNOWN, $attempt->getStatus());
        self::assertSame(0, $retryInvoice->getRefundedAmount());
        $policy->allowRefund = true;
        $resumed = $service->refund($retryInvoice, 40, 'retry', ['idempotencyKey' => $key]);
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $resumed->status);
        self::assertSame(InvoiceRefundAttempt::STATUS_COMPLETED, $attempt->getStatus());
        self::assertSame(40, $retryInvoice->getRefundedAmount());
    }

    public function testExternalPaidNotifyStillRefreshesInvoiceAndRemainsIdempotent(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'async-context', Invoice::SCENE_ORDER, 100));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $data = $invoice->getExtraData();
        $data['committed_elsewhere'] = ['reference' => 'keep-current'];
        $this->em->getConnection()->executeStatement('UPDATE payment_invoice SET extra_data = ? WHERE id = ?', [json_encode($data, JSON_THROW_ON_ERROR), $invoice->getId()]);
        $notifications = 0;
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $listener = static function () use (&$notifications): void { ++$notifications; };
        $dispatcher->addListener(\App\Payment\Event\InvoicePaidEvent::class, $listener);
        try {
            $notify = new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100, transactionId: 'async-transaction');
            $this->service->markPaid($invoice, $notify);
            $this->service->markPaid($invoice, $notify);
            self::assertSame(1, $notifications);
        } finally {
            $dispatcher->removeListener(\App\Payment\Event\InvoicePaidEvent::class, $listener);
        }
        $id = $invoice->getId();
        $this->em->clear();
        $reloaded = $this->em->find(Invoice::class, $id);
        self::assertSame('async-transaction', $reloaded->getTransactionId());
        self::assertSame(['reference' => 'keep-current'], $reloaded->getExtraData()['committed_elsewhere']);
        self::assertSame(100, $reloaded->getExtraData()['pay']['amount']);
    }

    public function testHandleNotifyResultFailed(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-5', Invoice::SCENE_DEPOSIT, 100));
        $failed = $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_FAILED,
            amount: 100,
            currency: 'CNY',
        ));
        self::assertSame(Invoice::STATUS_FAILED, $failed->getStatus());
    }

    public function testMarkPaidOnCancelledInvoiceThrows(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-6', Invoice::SCENE_DEPOSIT, 100));
        $this->service->cancel($invoice);
        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());

        $this->expectException(\App\Payment\Exception\InvoiceInvalidTransitionException::class);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
    }

    public function testMarkFailedOnPaidInvoiceReturnsEarly(): void
    {
        $payer = $this->createUser('paid-failed@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-7', Invoice::SCENE_DEPOSIT, 100, payer: $payer));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));

        $result = $this->service->markFailed($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_FAILED, 100));
        self::assertSame(Invoice::STATUS_PAID, $result->getStatus());
    }

    public function testRefundRejectsNonPositiveAmount(): void
    {
        $payer = $this->createUser('refund-zero@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-8', Invoice::SCENE_DEPOSIT, 100, payer: $payer));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->refund($invoice, 0, 'zero');
    }

    public function testRefundRejectsInvalidInvoiceStatus(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-9', Invoice::SCENE_DEPOSIT, 100));

        $this->expectException(\App\Payment\Exception\InvoiceInvalidTransitionException::class);
        $this->service->refund($invoice, 50, 'pending');
    }

    public function testRefundRejectsAmountExceedingRemaining(): void
    {
        $payer = $this->createUser('refund-excess@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-10', Invoice::SCENE_DEPOSIT, 100, payer: $payer));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
        $this->service->refund($invoice, 30, 'partial');
        self::assertSame(30, $invoice->getRefundedAmount());

        $this->expectException(\App\Payment\Exception\InvoiceAmountMismatchException::class);
        $this->service->refund($invoice, 80, 'exceeds remaining');
    }

    public function testMultiplePartialRefundsAreRecordedBeforeFinalRefund(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-multiple-refunds', Invoice::SCENE_DEPOSIT, 100));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));

        $first = $this->service->refund($invoice, 40, 'partial', ['idempotencyKey' => 'first']);
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $first->status);
        $second = $this->service->refund($invoice, 40, 'partial', ['idempotencyKey' => 'second']);
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $second->status);
        self::assertSame(80, $invoice->getRefundedAmount());
        $last = $this->service->refund($invoice, 20, 'final', ['idempotencyKey' => 'third']);
        self::assertSame(Invoice::STATUS_REFUNDED, $last->status);
        self::assertSame(100, $invoice->getRefundedAmount());
        self::assertCount(3, $this->em->getRepository(InvoiceRefundAttempt::class)->findBy([
            'invoiceUuid' => $invoice->getUuid(), 'status' => InvoiceRefundAttempt::STATUS_COMPLETED,
        ]));
    }

    public function testUncertainRefundBlocksNewKeysAndSameKeyCanResume(): void
    {
        $payer = $this->createUser('refund-uncertain@example.com');
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-uncertain', Invoice::SCENE_DEPOSIT, 100, payer: $payer));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
        $idempotencyKey = 'provider-timeout-retry-1';
        $attemptKey = hash('sha256', $invoice->getUuid()."\0".$idempotencyKey);
        $attempt = new InvoiceRefundAttempt($attemptKey, $invoice->getUuid(), 40, 'timeout refund', [
            'hasAdjustment' => false, 'adjustmentAmount' => 0, 'gatewayAmount' => 40,
            'gatewayPaidAmount' => 100, 'expectedRefundedAmount' => 0, 'payment' => Invoice::PAYMENT_MOCK,
            'adjustments' => [],
        ]);
        $attempt->markUnknown();
        $this->em->persist($attempt);
        $this->em->flush();

        try {
            $this->service->refund($invoice, 40, 'timeout refund', ['idempotencyKey' => 'different-request']);
            self::fail('A different refund must not pass an unresolved attempt.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('uncertain result', $exception->getMessage());
        }

        $result = $this->service->refund($invoice, 40, 'timeout refund', ['idempotencyKey' => $idempotencyKey]);
        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $result->status);
        self::assertSame(40, $invoice->getRefundedAmount());
        self::assertSame(InvoiceRefundAttempt::STATUS_COMPLETED, $attempt->getStatus());
    }

    public function testRefundRejectsOuterTransactionBeforeReservation(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'outer-transaction', Invoice::SCENE_DEPOSIT, 100));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->service->refund($invoice, 100, 'refund');
            self::fail('Refund must not run inside an outer transaction.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('outer transaction', $exception->getMessage());
            self::assertCount(0, $this->em->getRepository(InvoiceRefundAttempt::class)->findBy(['invoiceUuid' => $invoice->getUuid()]));
        } finally {
            $connection->rollBack();
        }
    }

    public function testUnknownAdjustedAndLegacyAttemptsCannotResume(): void
    {
        foreach ([null, [
            'hasAdjustment' => true, 'adjustmentAmount' => 40, 'gatewayAmount' => 60,
            'gatewayPaidAmount' => 60, 'expectedRefundedAmount' => 0, 'payment' => Invoice::PAYMENT_MOCK,
            'adjustments' => [['provider' => 'wallet', 'amount' => 40, 'currency' => 'CNY', 'referenceId' => 'original']],
        ]] as $allocation) {
            $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', uniqid('uncertain-', true), Invoice::SCENE_DEPOSIT, 100));
            $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
            $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
            $key = 'uncertain-adjustment';
            $attempt = new InvoiceRefundAttempt(hash('sha256', $invoice->getUuid()."\0".$key), $invoice->getUuid(), 100, 'refund', $allocation);
            $attempt->markUnknown();
            $this->em->persist($attempt);
            $this->em->flush();
            try {
                $this->service->refund($invoice, 100, 'refund', ['idempotencyKey' => $key]);
                self::fail('An uncertain adjustment must require reconciliation.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('manual reconciliation', $exception->getMessage());
            }
            $this->em->refresh($attempt);
            self::assertSame(InvoiceRefundAttempt::STATUS_UNKNOWN, $attempt->getStatus());
            self::assertEquals($allocation, $attempt->getAllocation());
            self::assertSame(0, $invoice->getRefundedAmount());
        }
    }

    public function testAdjustmentSideEffectThenFailureCannotBeRetried(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'adjustment-fault', Invoice::SCENE_DEPOSIT, 100));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
        $provider = new class implements PaymentAdjustmentProviderInterface {
            public bool $refunded = false;
            public static function getName(): string { return 'fault'; }
            public function supports(Invoice $invoice, string $payment, array $options): bool { return false; }
            public function apply(PaymentAdjustmentContext $context): PaymentAdjustmentResult { throw new \LogicException('Not used.'); }
            public function applied(Invoice $invoice): array { return $this->refunded ? [] : [new PaymentAdjustmentResult('fault', 40, 'CNY', 'original')]; }
            public function release(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult { throw new \LogicException('Not used.'); }
            public function refund(Invoice $invoice, PaymentAdjustmentResult $adjustment, string $reason): PaymentAdjustmentResult {
                $this->refunded = true;
                throw new \RuntimeException('Injected failure after adjustment side effect.');
            }
        };
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('doctrine.orm.entity_manager', $this->em);
        $container->set('logger', new \Psr\Log\NullLogger());
        $service = new InvoiceService($container, static::getContainer()->get(PaymentGatewayRegistry::class), new PaymentAdjustmentRegistry([$provider]), static::getContainer()->get('state_machine.invoice'), static::getContainer()->get('event_dispatcher'));
        try {
            $service->refund($invoice, 100, 'fault', ['idempotencyKey' => 'fault']);
            self::fail('The injected adjustment failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Injected failure', $exception->getMessage());
        }
        self::assertTrue($provider->refunded);
        $this->em->clear();
        $invoice = $this->em->getRepository(Invoice::class)->find($invoice->getId());
        $attempt = $this->em->getRepository(InvoiceRefundAttempt::class)->find(hash('sha256', $invoice->getUuid()."\0fault"));
        self::assertSame(InvoiceRefundAttempt::STATUS_UNKNOWN, $attempt->getStatus());
        self::assertSame(60, $attempt->getAllocation()['gatewayAmount']);
        self::assertSame(40, $attempt->getAllocation()['adjustmentAmount']);
        try {
            $service->refund($invoice, 100, 'fault', ['idempotencyKey' => 'fault']);
            self::fail('Retry after an adjustment side effect must be blocked.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('manual reconciliation', $exception->getMessage());
        }
        self::assertSame(0, $invoice->getRefundedAmount());
    }

    public function testOldProcessingRefundCannotBeTakenOver(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-processing', Invoice::SCENE_DEPOSIT, 100));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
        $key = 'long-running';
        $attempt = new InvoiceRefundAttempt(hash('sha256', $invoice->getUuid()."\0".$key), $invoice->getUuid(), 40, 'refund');
        $this->em->persist($attempt);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE payment_invoice_refund_attempt SET updated_at = ? WHERE attempt_key = ?',
            ['2000-01-01 00:00:00', $attempt->getAttemptKey()],
        );

        try {
            $this->service->refund($invoice, 40, 'refund', ['idempotencyKey' => $key]);
            self::fail('An old processing attempt must not be taken over.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already being processed', $exception->getMessage());
        }
        self::assertSame(0, $invoice->getRefundedAmount());
        self::assertSame(InvoiceRefundAttempt::STATUS_PROCESSING, $attempt->getStatus());
    }

    public function testRefundRefreshesCachedCompletedAttempt(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-refresh', Invoice::SCENE_DEPOSIT, 100));
        $this->service->pay($invoice, Invoice::PAYMENT_MOCK);
        $this->service->markPaid($invoice, new PaymentNotifyResult(Invoice::PAYMENT_MOCK, $invoice->getOutTradeNo(), Invoice::STATUS_PAID, 100));
        $key = 'completed-elsewhere';
        $attempt = new InvoiceRefundAttempt(hash('sha256', $invoice->getUuid()."\0".$key), $invoice->getUuid(), 40, 'refund');
        $this->em->persist($attempt);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE payment_invoice_refund_attempt SET status = ?, result = ? WHERE attempt_key = ?',
            [InvoiceRefundAttempt::STATUS_COMPLETED, json_encode(['amount' => 40, 'status' => Invoice::STATUS_PARTIAL_REFUNDED, 'refundId' => 'original', 'rawData' => []]), $attempt->getAttemptKey()],
        );

        $result = $this->service->refund($invoice, 40, 'refund', ['idempotencyKey' => $key]);
        self::assertSame('original', $result->refundId);
        self::assertSame(InvoiceRefundAttempt::STATUS_COMPLETED, $attempt->getStatus());
        self::assertSame(0, $invoice->getRefundedAmount());
    }

    public function testHandleNotifyResultRejectsUnsupportedStatus(): void
    {
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'src-11', Invoice::SCENE_DEPOSIT, 100));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->handleNotifyResult(new PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: 'unknown',
            amount: 100,
        ));
    }

    public function testRefundAndFulfillmentExcludeEachOtherAcrossGatewayCall(): void
    {
        $order = (new \App\Trade\Entity\Order())->setStatus('paid')->setTotalAmount(100);
        $invoice = (new Invoice())->setSourceType('trade_order')->setSourceId($order->getUuid())
            ->setAmount(100)->setStatus('paid')->setPayment('mock');
        $order->setInvoiceId($invoice->getUuid());
        $this->em->persist($order);
        $this->em->persist($invoice);
        $this->em->flush();
        $workflow = static::getContainer()->get('state_machine.order');
        $duringRefund = function () use ($order, $workflow): void {
            // Reservation has committed before the gateway runs. A fresh transaction
            // must still reject both workflow availability and actual application.
            $this->em->beginTransaction();
            try {
                $this->em->refresh($order);
                self::assertFalse($workflow->can($order, 'fulfill'));
                try {
                    $workflow->apply($order, 'fulfill');
                    self::fail('An in-flight refund must block fulfillment.');
                } catch (\Symfony\Component\Workflow\Exception\NotEnabledTransitionException) {
                }
            } finally {
                $this->em->rollback();
            }
        };
        $gateway = new class($duringRefund) implements \App\Payment\Service\PaymentGatewayInterface {
            public function __construct(private \Closure $duringRefund) {}
            public static function getName(): string { return 'mock'; }
            public function pay(Invoice $invoice, int $amount, array $options = []): \App\Payment\DTO\PaymentResult { throw new \LogicException('Not used.'); }
            public function notify(\Symfony\Component\HttpFoundation\Request $request): PaymentNotifyResult { throw new \LogicException('Not used.'); }
            public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): \App\Payment\DTO\PaymentRefundResult {
                ($this->duringRefund)();
                return new \App\Payment\DTO\PaymentRefundResult($invoice, $amount, Invoice::STATUS_REFUNDED, 'confirmed');
            }
            public function getNotifySuccessResponse(PaymentNotifyResult $result): \Symfony\Component\HttpFoundation\Response { throw new \LogicException('Not used.'); }
        };
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('doctrine.orm.entity_manager', $this->em);
        $service = new InvoiceService($container, new PaymentGatewayRegistry([$gateway]), new PaymentAdjustmentRegistry([]), static::getContainer()->get('state_machine.invoice'), static::getContainer()->get('event_dispatcher'),
            [static::getContainer()->get(\App\Trade\Service\OrderInvoiceSourcePolicy::class)]);
        $service->refund($invoice, 100, 'refund first');
        $this->em->refresh($order);
        self::assertSame('refunded', $order->getStatus());
        self::assertSame(100, $invoice->getRefundedAmount());

        // Fulfillment winning the order lock must reject a new refund before the
        // gateway is called, including direct invoice refunds, not only Trade APIs.
        $other = (new \App\Trade\Entity\Order())->setStatus('paid')->setTotalAmount(100);
        $otherInvoice = (new Invoice())->setSourceType('trade_order')->setSourceId($other->getUuid())
            ->setAmount(100)->setStatus('paid')->setPayment('mock');
        $other->setInvoiceId($otherInvoice->getUuid());
        $this->em->persist($other);
        $this->em->persist($otherInvoice);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE trade_order SET status = ? WHERE id = ?', ['fulfilled', $other->getId()]);
        try {
            $this->service->refund($otherInvoice, 100, 'fulfillment first');
            self::fail('A fulfilled order must not start a refund.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('linked order must be paid', $exception->getMessage());
        }
        self::assertCount(0, $this->em->getRepository(InvoiceRefundAttempt::class)->findBy(['invoiceUuid' => $otherInvoice->getUuid()]));
    }

    public function testMysqlRefundBarrierUsesCurrentReadsAfterSnapshotWasEstablished(): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            self::markTestSkipped('Requires an isolated MySQL test database.');
        }
        $invoice = $this->service->createInvoice(new CreateInvoiceRequest('manual', 'mysql-snapshot', Invoice::SCENE_ORDER, 100));
        $other = \Doctrine\DBAL\DriverManager::getConnection($connection->getParams());
        $key = hash('sha256', $invoice->getUuid().'snapshot');
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->beginTransaction();
        try {
            $connection->fetchOne('SELECT COUNT(*) FROM payment_invoice_refund_attempt');
            $other->insert('payment_invoice_refund_attempt', [
                'attempt_key' => $key, 'uuid' => \App\Core\Utils\UUID::v4(), 'invoice_uuid' => $invoice->getUuid(),
                'requested_amount' => 40, 'status' => 'processing', 'reason' => 'concurrent',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]);
            $attempts = $this->em->getRepository(InvoiceRefundAttempt::class);
            self::assertSame('processing', $attempts->findCurrent($key)?->getStatus());
            self::assertCount(1, $attempts->unresolved($invoice->getUuid()));
            self::assertTrue($attempts->hasUnresolvedForSource('manual', 'mysql-snapshot'));
        } finally {
            $connection->rollBack();
            $other->delete('payment_invoice_refund_attempt', ['attempt_key' => $key]);
            $other->close();
        }
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(strstr($email, '@', true));
        $user->setPassword('password');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
