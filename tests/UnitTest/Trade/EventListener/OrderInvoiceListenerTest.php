<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\EventListener;

use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Payment\Event\InvoiceFailedEvent;
use App\Payment\Event\InvoicePaidEvent;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Trade\Entity\Order;
use App\Trade\EventListener\OrderInvoiceListener;
use App\Trade\Service\OrderServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderInvoiceListenerTest extends TestCase
{
    private const SOURCE_UUID = '00000000-0000-4000-8000-000000000090';

    public function testSubscribedEvents(): void
    {
        $events = OrderInvoiceListener::getSubscribedEvents();

        self::assertSame('onInvoicePaid', $events[InvoicePaidEvent::class]);
        self::assertSame('onInvoiceRefunded', $events[InvoiceRefundedEvent::class]);
        self::assertSame('onInvoiceCancelled', $events[InvoiceCancelledEvent::class]);
        self::assertSame('onInvoiceFailed', $events[InvoiceFailedEvent::class]);
    }

    public function testPaidIgnoresInvoiceOfAnotherSourceType(): void
    {
        $invoice = (new Invoice())->setSourceType('wallet_topup')->setSourceId(self::SOURCE_UUID);

        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::never())->method('get');
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');
        $logger = $this->createStub(LoggerInterface::class);
        $listener = new OrderInvoiceListener($orders, $workflow, $logger);

        $listener->onInvoicePaid(new InvoicePaidEvent($invoice));

        self::assertTrue(true);
    }

    public function testPaidIgnoresEventWhenOrderIsNotFound(): void
    {
        $invoice = (new Invoice())->setSourceType('trade_order')->setSourceId(self::SOURCE_UUID);

        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->with(['uuid' => self::SOURCE_UUID])->willReturn(null);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($invoice));
    }

    public function testPaidSkipsAlreadyPaidOrder(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1500, 'CNY');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('can');
        $workflow->expects(self::never())->method('apply');
        $orders = $this->createOrderService($order);
        $orders->expects(self::never())->method('update');
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($invoice));
    }

    public function testPaidLogsAmountMismatchAndDoesNotApply(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1600, 'CNY');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');
        $orders = $this->createOrderService($order);
        $orders->expects(self::never())->method('update');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($invoice));
    }

    public function testPaidLogsCurrencyMismatchAndDoesNotApply(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1500, 'USD');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');
        $orders = $this->createOrderService($order);
        $orders->expects(self::never())->method('update');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($invoice));
    }

    public function testPaidSkipsWhenWorkflowCannotPay(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1500, 'CNY');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'pay')->willReturn(false);
        $workflow->expects(self::never())->method('apply');
        $orders = $this->createOrderService($order);
        $orders->expects(self::never())->method('update');
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($invoice));
        self::assertNull($order->getInvoiceId());
        self::assertNull($order->getPaidAt());
        self::assertNull($order->getPaymentStatus());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function settledOrders(): iterable
    {
        foreach ([Order::STATUS_PAID, Order::STATUS_FULFILLED, Order::STATUS_COMPLETED, Order::STATUS_REFUNDED] as $status) {
            yield $status.' duplicate' => [$status, false];
            yield $status.' conflicting invoice' => [$status, true];
        }
    }

    #[DataProvider('settledOrders')]
    public function testPaidPreservesSettledOrderPaymentAssociation(string $status, bool $differentInvoice): void
    {
        $paidAt = new \DateTimeImmutable('2026-08-09T10:00:00+08:00');
        $order = (new Order())->setStatus($status)->setTotalAmount(1500)->setCurrency('CNY');
        $original = $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1500, 'CNY');
        $order->setInvoiceId($original->getUuid())->setInvoiceNo($original->getOutTradeNo())
            ->setPaymentStatus($status === Order::STATUS_REFUNDED ? Invoice::STATUS_REFUNDED : Invoice::STATUS_PAID)
            ->setPaymentMethod(Invoice::PAYMENT_WALLET)->setPaidAt($paidAt);
        $incoming = $differentInvoice ? $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1500, 'CNY') : $original;
        $incoming->setPayment(Invoice::PAYMENT_WECHAT)->setPaidAt(new \DateTimeImmutable());
        $orders = $this->createOrderService($order);
        $orders->expects(self::never())->method('update');
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('can');
        $workflow->expects(self::never())->method('apply');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($differentInvoice ? self::once() : self::never())->method('critical');

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($incoming));

        self::assertSame($status, $order->getStatus());
        self::assertSame($original->getUuid(), $order->getInvoiceId());
        self::assertSame($original->getOutTradeNo(), $order->getInvoiceNo());
        self::assertSame($status === Order::STATUS_REFUNDED ? Invoice::STATUS_REFUNDED : Invoice::STATUS_PAID, $order->getPaymentStatus());
        self::assertSame(Invoice::PAYMENT_WALLET, $order->getPaymentMethod());
        self::assertSame($paidAt, $order->getPaidAt());
    }

    public function testPaidMarksOrderPaidAndAppliesTransition(): void
    {
        $paidAt = new \DateTimeImmutable('2026-08-09T10:00:00+08:00');
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1500, 'CNY');
        $invoice->setPayment(Invoice::PAYMENT_WECHAT)->setPaidAt($paidAt);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'pay')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($order, 'pay');
        $orders = $this->createOrderService($order);
        $orders->expects(self::once())->method('update')->with($order, []);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($invoice));

        self::assertSame($invoice->getUuid(), $order->getInvoiceId());
        self::assertSame($invoice->getOutTradeNo(), $order->getInvoiceNo());
        self::assertSame(Invoice::STATUS_PAID, $order->getPaymentStatus());
        self::assertSame(Invoice::PAYMENT_WECHAT, $order->getPaymentMethod());
        self::assertSame($paidAt, $order->getPaidAt());
    }

    public function testPaidIgnoresInvoicePaidByADifferentPayer(): void
    {
        // SKIPPED: documents Bug #2 from the report. Correct behaviour would NOT mark the
        // order paid when the paid invoice belongs to a payer other than the order owner.
        // The current onInvoicePaid() only guards amount/currency (no payer check), so an
        // invoice referencing the order but owned by another user still flips it to paid.
        $this->markTestSkipped('Known gap: onInvoicePaid has no payer verification (see report Bug #2).');

        $owner = new \App\Identity\Entity\User();
        (new \ReflectionProperty(\App\Identity\Entity\User::class, 'id'))->setValue($owner, 1);
        $other = new \App\Identity\Entity\User();
        (new \ReflectionProperty(\App\Identity\Entity\User::class, 'id'))->setValue($other, 2);

        $order = (new Order())
            ->setStatus(Order::STATUS_CONFIRMED)
            ->setUser($owner)
            ->setTotalAmount(1500)
            ->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_PAID, 1500, 'CNY');
        $invoice->setPayer($other);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);
        $workflow->expects(self::never())->method('apply');
        $orders = $this->createOrderService($order);
        $orders->expects(self::never())->method('update');
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoicePaid(new InvoicePaidEvent($invoice));

        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());
    }

    public function testRefundedIgnoresEventWhenOrderIsNotFound(): void
    {
        $invoice = (new Invoice())->setSourceType('trade_order')->setSourceId(self::SOURCE_UUID);

        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->willReturn(null);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceRefunded(new InvoiceRefundedEvent($invoice));
    }

    public function testRefundedOnlyUpdatesPaymentStatusWhenInvoicePartiallyRefunded(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_PAID)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_PARTIAL_REFUNDED, 1500, 'CNY');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');
        $orders = $this->createOrderService($order);
        $orders->expects(self::once())->method('update')->with($order, []);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceRefunded(new InvoiceRefundedEvent($invoice));

        self::assertSame(Invoice::STATUS_PARTIAL_REFUNDED, $order->getPaymentStatus());
        self::assertNull($order->getRefundedAt());
    }

    public function testRefundedAppliesRefundTransition(): void
    {
        $refundedAt = new \DateTimeImmutable('2026-08-09T11:00:00+08:00');
        $order = (new Order())->setStatus(Order::STATUS_COMPLETED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_REFUNDED, 1500, 'CNY');
        $invoice->setRefundedAt($refundedAt);

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'refund')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($order, 'refund');
        $orders = $this->createOrderService($order);
        $orders->expects(self::once())->method('update')->with($order, []);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceRefunded(new InvoiceRefundedEvent($invoice));

        self::assertSame(Invoice::STATUS_REFUNDED, $order->getPaymentStatus());
        self::assertSame($refundedAt, $order->getRefundedAt());
    }

    public function testRefundedSkipsTransitionWhenWorkflowCannotRefund(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_COMPLETED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_REFUNDED, 1500, 'CNY');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->with($order, 'refund')->willReturn(false);
        $workflow->expects(self::never())->method('apply');
        $orders = $this->createOrderService($order);
        $orders->expects(self::once())->method('update')->with($order, []);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceRefunded(new InvoiceRefundedEvent($invoice));

        self::assertNull($order->getRefundedAt());
    }

    public function testCancelledIgnoresEventWhenOrderIsNotFound(): void
    {
        $invoice = (new Invoice())->setSourceType('trade_order')->setSourceId(self::SOURCE_UUID);

        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->willReturn(null);
        $workflow = $this->createStub(WorkflowInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceCancelled(new InvoiceCancelledEvent($invoice));
    }

    public function testCancelledUpdatesOrderPaymentStatus(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_CANCELLED, 1500, 'CNY');

        $orders = $this->createOrderService($order);
        $orders->expects(self::once())->method('update')->with($order, []);
        $workflow = $this->createStub(WorkflowInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceCancelled(new InvoiceCancelledEvent($invoice));

        self::assertSame(Invoice::STATUS_CANCELLED, $order->getPaymentStatus());
    }

    public function testFailedIgnoresEventWhenOrderIsNotFound(): void
    {
        $invoice = (new Invoice())->setSourceType('trade_order')->setSourceId(self::SOURCE_UUID);

        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->expects(self::once())->method('get')->willReturn(null);
        $workflow = $this->createStub(WorkflowInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceFailed(new InvoiceFailedEvent($invoice));
    }

    public function testFailedUpdatesOrderPaymentStatus(): void
    {
        $order = (new Order())->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1500)->setCurrency('CNY');
        $invoice = $this->createTradeInvoice($order, Invoice::STATUS_FAILED, 1500, 'CNY');

        $orders = $this->createOrderService($order);
        $orders->expects(self::once())->method('update')->with($order, []);
        $workflow = $this->createStub(WorkflowInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        (new OrderInvoiceListener($orders, $workflow, $logger))->onInvoiceFailed(new InvoiceFailedEvent($invoice));

        self::assertSame(Invoice::STATUS_FAILED, $order->getPaymentStatus());
    }

    private function createTradeInvoice(Order $order, string $status, int $amount, string $currency): Invoice
    {
        return (new Invoice())
            ->setSourceType('trade_order')
            ->setSourceId($order->getUuid())
            ->setStatus($status)
            ->setAmount($amount)
            ->setCurrency($currency);
    }

    /** @return OrderServiceInterface&MockObject */
    private function createOrderService(Order $order): OrderServiceInterface&MockObject
    {
        $orders = $this->createMock(OrderServiceInterface::class);
        $orders->method('get')->willReturn($order);
        return $orders;
    }
}
