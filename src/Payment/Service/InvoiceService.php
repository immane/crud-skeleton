<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Core\Service\BaseService;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentAdjustmentResult;
use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Entity\InvoiceRefundAttempt;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Payment\Event\InvoiceFailedEvent;
use App\Payment\Event\InvoicePaidEvent;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Payment\Exception\InvoiceAmountMismatchException;
use App\Payment\Exception\InvoiceInvalidTransitionException;
use App\Payment\Repository\InvoiceRefundAttemptRepository;
use App\Payment\Service\Adjustment\PaymentAdjustmentRegistry;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/** @extends BaseService<\App\Payment\Entity\Invoice> */
final class InvoiceService extends BaseService implements InvoiceServiceInterface
{
    /** @var array<string, InvoiceSourcePolicyInterface> */
    private readonly array $sourcePolicies;

    /** @param iterable<InvoiceSourcePolicyInterface> $sourcePolicies */
    public function __construct(
        ContainerInterface $container,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly PaymentAdjustmentRegistry $adjustmentRegistry,
        #[Target('state_machine.invoice')]
        private readonly WorkflowInterface $workflow,
        private readonly EventDispatcherInterface $dispatcher,
        #[AutowireIterator('payment.invoice_source_policy')]
        iterable $sourcePolicies = [],
    ) {
        parent::__construct($container, Invoice::class);
        $policies = [];
        foreach ($sourcePolicies as $policy) {
            $type = $policy::getSourceType();
            if (isset($policies[$type])) {
                throw new \LogicException(sprintf('Multiple invoice source policies registered for "%s".', $type));
            }
            $policies[$type] = $policy;
        }
        $this->sourcePolicies = $policies;
    }

    public function createInvoice(CreateInvoiceRequest $request): Invoice
    {
        if ($request->amount < 0) {
            throw new \InvalidArgumentException('Invoice amount cannot be negative.');
        }

        return $this->wrapInTransaction(function () use ($request) {
            $invoice = new Invoice();
            $invoice->setSourceType($request->sourceType);
            $invoice->setSourceId($request->sourceId);
            $invoice->setScene($request->scene);
            $invoice->setAmount($request->amount);
            $invoice->setCurrency($request->currency);
            $invoice->setPayer($request->payer);
            $invoice->setSubject($request->subject);
            $invoice->setDescription($request->description);
            $invoice->setExtraData($request->extraData ?: null);

            $this->getEntityManager()->persist($invoice);

            return $invoice;
        });
    }

    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, string $payment, array $options = []): PaymentResult
    {
        return $this->wrapInTransaction(function () use ($invoice, $payment, $options) {
            $this->lockInvoiceAggregate($invoice);
            if (!$this->workflow->can($invoice, 'start_pay')) {
                throw new InvoiceInvalidTransitionException($invoice, 'start_pay');
            }

            $adjustments = $this->adjustmentRegistry->apply($invoice, $payment, $options);
            $adjustmentAmount = self::sumAdjustments($adjustments);
            $gatewayAmount = $invoice->getAmount() - $adjustmentAmount;

            if ($gatewayAmount < 0) {
                throw new InvoiceAmountMismatchException('Deduction amount exceeds invoice amount.');
            }

            $effectivePayment = $gatewayAmount === 0 ? Invoice::PAYMENT_WALLET : $payment;
            $invoice->setPayment($effectivePayment);
            if (isset($options['gateway'])) {
                $invoice->setGateway((string) $options['gateway']);
            }
            if (isset($options['tradeType'])) {
                $invoice->setTradeType((string) $options['tradeType']);
            }
            $this->workflow->apply($invoice, 'start_pay');
            $this->getEntityManager()->flush();

            if ($gatewayAmount === 0) {
                $payload = [
                    'adjustmentOnly' => true,
                    'gatewayAmount' => 0,
                    'adjustments' => array_map(static fn (PaymentAdjustmentResult $adjustment): array => [
                        'provider' => $adjustment->provider,
                        'amount' => $adjustment->amount,
                        'currency' => $adjustment->currency,
                        'referenceId' => $adjustment->referenceId,
                    ], $adjustments),
                ];
                if (($firstPayload = $adjustments[0]->payload ?? []) !== []) {
                    $payload += $firstPayload;
                }
                $this->markPaidLocked($invoice, new PaymentNotifyResult(
                    payment: Invoice::PAYMENT_WALLET,
                    outTradeNo: $invoice->getOutTradeNo(),
                    status: Invoice::STATUS_PAID,
                    amount: 0,
                    currency: $invoice->getCurrency(),
                    transactionId: $payload['transactionId'] ?? null,
                    paidAt: new \DateTimeImmutable(),
                    rawData: $payload,
                ));

                return new PaymentResult(
                    invoice: $invoice,
                    status: Invoice::STATUS_PAID,
                    payload: $payload,
                    message: 'Wallet deduction payment completed',
                );
            }

            try {
                $gateway = $this->gatewayRegistry->get($payment);
                $result = $gateway->pay($invoice, $gatewayAmount, $options);
            } catch (\Throwable $e) {
                if ($adjustments !== []) {
                    $this->adjustmentRegistry->releaseApplied($invoice, 'Gateway payment failed: ' . $e->getMessage());
                }
                throw $e;
            }
            $payload = $result->payload ?? [];
            if ($payload) {
                $invoice->appendExtraData('pay', $this->sanitizePayload($payload));
            }

            if ($result->status === Invoice::STATUS_PAID) {
                $this->markPaidLocked($invoice, new PaymentNotifyResult(
                    payment: $payment,
                    outTradeNo: $invoice->getOutTradeNo(),
                    status: Invoice::STATUS_PAID,
                    amount: $gatewayAmount,
                    currency: $invoice->getCurrency(),
                    transactionId: $payload['transactionId'] ?? null,
                    paidAt: new \DateTimeImmutable(),
                    rawData: $payload,
                ));
            }

            return $result;
        });
    }

    public function handleNotifyResult(PaymentNotifyResult $result): Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = $this->getRepository()->findOneBy(['outTradeNo' => $result->outTradeNo]);
        if (!$invoice) {
            throw new \RuntimeException(sprintf('Invoice %s not found.', $result->outTradeNo));
        }

        return match ($result->status) {
            Invoice::STATUS_PAID => $this->markPaid($invoice, $result),
            Invoice::STATUS_FAILED => $this->markFailed($invoice, $result),
            default => throw new \InvalidArgumentException(sprintf('Unsupported notify status "%s".', $result->status)),
        };
    }

    public function markPaid(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        return $this->wrapInTransaction(function () use ($invoice, $result) {
            $this->lockInvoiceAggregate($invoice);

            return $this->markPaidLocked($invoice, $result);
        });
    }

    /** Called only after acquiring the aggregate locks; never refresh local gateway changes. */
    private function markPaidLocked(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        if ($invoice->getStatus() === Invoice::STATUS_PAID) {
            return $invoice;
        }
        if ($invoice->getStatus() === Invoice::STATUS_CANCELLED) {
            throw new InvoiceInvalidTransitionException($invoice, 'mark_paid');
        }
        $expectedAmount = $invoice->getAmount() - $this->adjustmentRegistry->sumAppliedAmount($invoice);
        if ($expectedAmount !== $result->amount || $invoice->getCurrency() !== strtoupper($result->currency)) {
            throw new InvoiceAmountMismatchException('Payment notify amount or currency does not match invoice.');
        }
        if (!$this->workflow->can($invoice, 'mark_paid')) {
            throw new InvoiceInvalidTransitionException($invoice, 'mark_paid');
        }
        $invoice->setPayment($result->payment);
        $invoice->setTransactionId($result->transactionId);
        $invoice->setPaidAt($result->paidAt ?? new \DateTimeImmutable());
        $invoice->appendExtraData('notify', $this->sanitizePayload($result->rawData));
        $this->workflow->apply($invoice, 'mark_paid');
        $this->getEntityManager()->flush();
        $this->dispatcher->dispatch(new InvoicePaidEvent($invoice, $result));

        return $invoice;
    }

    public function markFailed(Invoice $invoice, PaymentNotifyResult $result): Invoice
    {
        return $this->wrapInTransaction(function () use ($invoice, $result) {
            $this->lockInvoiceAggregate($invoice);
            if ($invoice->getStatus() === Invoice::STATUS_PAID) {
                return $invoice;
            }
            if (!$this->workflow->can($invoice, 'fail')) {
                throw new InvoiceInvalidTransitionException($invoice, 'fail');
            }
            $this->adjustmentRegistry->releaseApplied($invoice, 'Invoice payment failed.');
            $invoice->appendExtraData('notify_failed', $this->sanitizePayload($result->rawData));
            $this->workflow->apply($invoice, 'fail');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoiceFailedEvent($invoice, $result));

            return $invoice;
        });
    }

    public function cancel(Invoice $invoice, ?string $reason = null): Invoice
    {
        return $this->wrapInTransaction(function () use ($invoice, $reason) {
            $this->lockInvoiceAggregate($invoice);
            if (!$this->workflow->can($invoice, 'cancel')) {
                throw new InvoiceInvalidTransitionException($invoice, 'cancel');
            }
            $this->adjustmentRegistry->releaseApplied($invoice, $reason ?? 'Invoice cancelled.');
            if ($reason !== null) {
                $invoice->appendExtraData('cancel', ['reason' => $reason]);
            }
            $invoice->setCancelledAt(new \DateTimeImmutable());
            $this->workflow->apply($invoice, 'cancel');
            $this->getEntityManager()->flush();
            $this->dispatcher->dispatch(new InvoiceCancelledEvent($invoice));

            return $invoice;
        });
    }

    /** @param array<string, mixed> $options */
    public function refund(Invoice $invoice, int $amount, string $reason, array $options = []): PaymentRefundResult
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive.');
        }
        if ($this->getEntityManager()->getConnection()->isTransactionActive()) {
            throw new \LogicException('Refund cannot run inside an outer transaction; the reservation must commit before any refund side effects.');
        }
        $requestKey = $options['idempotencyKey'] ?? hash('sha256', $amount."\0".$reason);
        if (!is_string($requestKey) || $requestKey === '' || strlen($requestKey) > 200) {
            throw new \InvalidArgumentException('idempotencyKey must be a non-empty string of at most 200 characters.');
        }
        $attemptKey = hash('sha256', $invoice->getUuid()."\0".$requestKey);

        $reservation = $this->wrapInTransaction(
            /** @return array{completed: array<string, mixed>|null, expectedRefundedAmount: int, hasAdjustment: bool, payment: string} */
            function () use ($invoice, $amount, $reason, $attemptKey): array {
            $sourcePolicy = $this->lockInvoiceAggregate($invoice);
            $em = $this->getEntityManager();
            $attempt = $this->refundAttempts()->findCurrent($attemptKey);
            if ($attempt instanceof InvoiceRefundAttempt) {
                if ($attempt->getInvoiceUuid() !== $invoice->getUuid() || $attempt->getRequestedAmount() !== $amount || $attempt->getReason() !== $reason) {
                    throw new \LogicException('Refund idempotency key was already used for a different request.');
                }
                if ($attempt->getStatus() === InvoiceRefundAttempt::STATUS_COMPLETED) {
                    $result = $attempt->getResult();
                    if ($result === null) {
                        throw new \LogicException('Completed refund attempt has no result; reconciliation is required.');
                    }
                    return [
                        'completed' => $result,
                        'expectedRefundedAmount' => $invoice->getRefundedAmount(),
                        'hasAdjustment' => false,
                        'payment' => $invoice->getPayment() ?? '',
                        'gatewayAmount' => 0,
                        'gatewayPaidAmount' => 0,
                    ];
                }
                if ($attempt->getStatus() === InvoiceRefundAttempt::STATUS_PROCESSING) {
                    throw new \RuntimeException('This refund request is already being processed; reconcile abandoned attempts before retrying.');
                }
                $allocation = $attempt->getAllocation();
                if ($allocation === null || ($allocation['hasAdjustment'] ?? true) !== false) {
                    throw new \RuntimeException('Refund allocation or adjustment outcome is uncertain; manual reconciliation is required before retrying.');
                }
                $sourcePolicy?->assertRefundable($invoice);
                $this->assertNoOtherRefundAttempt($invoice, $attemptKey);
                $attempt->resume();
            } else {
                $sourcePolicy?->assertRefundable($invoice);
                $this->assertNoOtherRefundAttempt($invoice, $attemptKey);
                $adjustments = $this->adjustmentRegistry->applied($invoice);
                $hasAdjustment = $adjustments !== [];
                if ($hasAdjustment && $amount !== $invoice->getAmount()) {
                    throw new InvoiceAmountMismatchException('Adjusted invoices only support full refund.');
                }
                $remaining = $invoice->getAmount() - $invoice->getRefundedAmount();
                if ($amount > $remaining) {
                    throw new InvoiceAmountMismatchException('Refund amount exceeds paid remaining amount.');
                }
                if (!in_array($invoice->getStatus(), [Invoice::STATUS_PAID, Invoice::STATUS_PARTIAL_REFUNDED], true)) {
                    throw new InvoiceInvalidTransitionException($invoice, 'refund');
                }
                $transition = $amount === $remaining ? 'refund' : 'partial_refund';
                if (!$this->workflow->can($invoice, $transition)) {
                    throw new InvoiceInvalidTransitionException($invoice, $transition);
                }
                $adjustmentAmount = self::sumAdjustments($adjustments);
                $allocation = [
                    'hasAdjustment' => $hasAdjustment,
                    'adjustmentAmount' => $adjustmentAmount,
                    'gatewayAmount' => $hasAdjustment ? $amount - $adjustmentAmount : $amount,
                    'gatewayPaidAmount' => $invoice->getAmount() - $adjustmentAmount,
                    'expectedRefundedAmount' => $invoice->getRefundedAmount(),
                    'payment' => $invoice->getPayment() ?? throw new \RuntimeException('Invoice has no payment gateway.'),
                    'adjustments' => self::serializeAdjustments($adjustments),
                ];
                $attempt = new InvoiceRefundAttempt($attemptKey, $invoice->getUuid(), $amount, $reason, $allocation);
                $em->persist($attempt);
            }
            $em->flush();

            return [
                'completed' => null,
                'expectedRefundedAmount' => $allocation['expectedRefundedAmount'],
                'hasAdjustment' => $allocation['hasAdjustment'],
                'payment' => $allocation['payment'],
                'gatewayAmount' => $allocation['gatewayAmount'],
                'gatewayPaidAmount' => $allocation['gatewayPaidAmount'],
            ];
            },
        );

        if (is_array($reservation['completed'] ?? null)) {
            $completed = $reservation['completed'];
            return new PaymentRefundResult(
                $invoice,
                (int) ($completed['amount'] ?? $amount),
                (string) ($completed['status'] ?? $invoice->getStatus()),
                is_string($completed['refundId'] ?? null) ? $completed['refundId'] : null,
                is_array($completed['rawData'] ?? null) ? $completed['rawData'] : [],
            );
        }

        $hasAdjustment = (bool) $reservation['hasAdjustment'];
        $payment = (string) $reservation['payment'];
        $expectedRefundedAmount = (int) $reservation['expectedRefundedAmount'];
        $gatewayAmount = (int) $reservation['gatewayAmount'];
        $rawData = [];
        $refundId = null;
        try {
            if ($gatewayAmount > 0) {
                $gateway = $this->gatewayRegistry->get($payment);
                $gatewayOptions = $options;
                $gatewayOptions['refundIdempotencyKey'] = $attemptKey;
                $gatewayPaidAmount = (int) $reservation['gatewayPaidAmount'];
                $result = $gateway->refund($invoice, $gatewayAmount, $gatewayPaidAmount, $reason, $gatewayOptions);
                if ($result->amount !== $gatewayAmount) {
                    throw new \RuntimeException('Payment gateway refund amount does not match the requested amount.');
                }
                $refundId = $result->refundId;
                $rawData['gateway'] = $result->rawData;
            }

            if ($hasAdjustment) {
                $rawData['adjustments'] = self::serializeAdjustments($this->adjustmentRegistry->refundApplied($invoice, $reason));
            }
        } catch (\Throwable $exception) {
            $this->markRefundAttemptUnknown($invoice, $attemptKey);
            throw $exception;
        }

        return $this->wrapInTransaction(function () use ($invoice, $amount, $rawData, $refundId, $attemptKey, $expectedRefundedAmount): PaymentRefundResult {
            $this->lockInvoiceAggregate($invoice);
            $em = $this->getEntityManager();
            $attempt = $this->refundAttempts()->findCurrent($attemptKey);
            if (!$attempt instanceof InvoiceRefundAttempt) {
                throw new \LogicException('Refund attempt disappeared before finalization.');
            }
            if ($attempt->getStatus() === InvoiceRefundAttempt::STATUS_COMPLETED) {
                $completed = $attempt->getResult() ?? [];
                return new PaymentRefundResult(
                    $invoice,
                    (int) ($completed['amount'] ?? $amount),
                    (string) ($completed['status'] ?? $invoice->getStatus()),
                    is_string($completed['refundId'] ?? null) ? $completed['refundId'] : null,
                    is_array($completed['rawData'] ?? null) ? $completed['rawData'] : [],
                );
            }
            if ($invoice->getRefundedAmount() !== $expectedRefundedAmount) {
                throw new \RuntimeException('Invoice refund state changed while the gateway refund was in progress; reconciliation is required.');
            }
            $newRefundedAmount = $invoice->getRefundedAmount() + $amount;
            $invoice->setRefundedAmount($newRefundedAmount);
            $invoice->appendExtraData('refund_' . ($refundId ?? count($invoice->getExtraData() ?? [])), $this->sanitizePayload($rawData));

            $transition = $newRefundedAmount >= $invoice->getAmount() ? 'refund' : 'partial_refund';
            if (!$this->workflow->can($invoice, $transition)) {
                throw new InvoiceInvalidTransitionException($invoice, $transition);
            }
            if ($transition === 'refund') {
                $invoice->setRefundedAt(new \DateTimeImmutable());
            }
            $this->workflow->apply($invoice, $transition);
            $safeRawData = $this->sanitizePayload($rawData);
            $attempt->complete($refundId, [
                'amount' => $amount,
                'status' => $invoice->getStatus(),
                'refundId' => $refundId,
                'rawData' => $safeRawData,
            ]);
            $this->getEntityManager()->flush();

            $finalResult = new PaymentRefundResult($invoice, $amount, $invoice->getStatus(), $refundId, $safeRawData);
            $this->dispatcher->dispatch(new InvoiceRefundedEvent($invoice, $finalResult));

            return $finalResult;
        });
    }

    private function assertNoOtherRefundAttempt(Invoice $invoice, string $attemptKey): void
    {
        $attempts = $this->refundAttempts()->unresolved($invoice->getUuid());
        foreach ($attempts as $attempt) {
            if ($attempt instanceof InvoiceRefundAttempt && $attempt->getAttemptKey() !== $attemptKey) {
                throw new \RuntimeException(sprintf('Refund attempt %s has an uncertain result; retry or reconcile it before issuing another refund.', $attempt->getAttemptKey()));
            }
        }
    }

    private function refundAttempts(): InvoiceRefundAttemptRepository
    {
        return $this->getEntityManager()->getRepository(InvoiceRefundAttempt::class);
    }

    private function markRefundAttemptUnknown(Invoice $invoice, string $attemptKey): void
    {
        $this->wrapInTransaction(function () use ($invoice, $attemptKey): void {
            $this->lockInvoiceAggregate($invoice);
            $attempt = $this->refundAttempts()->findCurrent($attemptKey);
            if ($attempt instanceof InvoiceRefundAttempt && $attempt->getStatus() === InvoiceRefundAttempt::STATUS_PROCESSING) {
                $attempt->markUnknown();
                $this->getEntityManager()->flush();
            }
        });
    }

    /** Lock the source first, then the invoice, before any state checks. */
    private function lockInvoiceAggregate(Invoice $invoice): ?InvoiceSourcePolicyInterface
    {
        $em = $this->getEntityManager();
        $lock = $em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform
            ? LockMode::NONE : LockMode::PESSIMISTIC_WRITE;
        $sourcePolicy = $this->sourcePolicies[$invoice->getSourceType()] ?? null;
        $sourcePolicy?->lock($invoice);
        $em->refresh($invoice, $lock);
        return $sourcePolicy;
    }

    /** @return list<Invoice> */
    public function findBySource(string $sourceType, string $sourceId): array
    {
        /** @var list<Invoice> */
        return $this->getRepository()->findBy(['sourceType' => $sourceType, 'sourceId' => $sourceId], ['id' => 'DESC']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        foreach (['password', 'secret', 'token', 'privateKey', 'signature'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }
        return $payload;
    }

    /** @param PaymentAdjustmentResult[] $adjustments */
    private static function sumAdjustments(array $adjustments): int
    {
        $sum = 0;
        foreach ($adjustments as $adjustment) {
            $sum += $adjustment->amount;
        }

        return $sum;
    }

    /** @param PaymentAdjustmentResult[] $adjustments
     * @return mixed[][] */
    private static function serializeAdjustments(array $adjustments): array
    {
        return array_map(static fn (PaymentAdjustmentResult $adjustment): array => [
            'provider' => $adjustment->provider,
            'amount' => $adjustment->amount,
            'currency' => $adjustment->currency,
            'referenceId' => $adjustment->referenceId,
            'payload' => $adjustment->payload,
        ], $adjustments);
    }
}
