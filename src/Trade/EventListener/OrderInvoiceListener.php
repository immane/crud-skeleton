<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Payment\Event\InvoiceFailedEvent;
use App\Payment\Event\InvoicePaidEvent;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderInvoiceListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly OrderServiceInterface $orderService,
        #[Target('state_machine.order')]
        private readonly WorkflowInterface $workflow,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            InvoicePaidEvent::class => 'onInvoicePaid',
            InvoiceRefundedEvent::class => 'onInvoiceRefunded',
            InvoiceCancelledEvent::class => 'onInvoiceCancelled',
            InvoiceFailedEvent::class => 'onInvoiceFailed',
        ];
    }

    public function onInvoicePaid(InvoicePaidEvent $event): void
    {
        $invoice = $event->getInvoice();
        $order = $this->findOrder($invoice);
        if (!$order instanceof Order) {
            return;
        }
        if ($invoice->getAmount() !== $order->getTotalAmount() || $invoice->getCurrency() !== $order->getCurrency()) {
            $this->logger->critical('Invoice/order payment mismatch', ['invoice' => $invoice->getOutTradeNo(), 'order' => $order->getUuid()]);
            return;
        }
        if (in_array($order->getStatus(), [Order::STATUS_PAID, Order::STATUS_FULFILLED, Order::STATUS_COMPLETED, Order::STATUS_REFUNDED], true)) {
            if ($order->getInvoiceId() !== null && $order->getInvoiceId() !== $invoice->getUuid()) {
                $this->logger->critical('A different invoice was paid after the Trade order was already settled.', [
                    'invoice' => $invoice->getOutTradeNo(),
                    'invoiceUuid' => $invoice->getUuid(),
                    'order' => $order->getUuid(),
                    'orderStatus' => $order->getStatus(),
                    'linkedInvoiceUuid' => $order->getInvoiceId(),
                ]);
            }
            return;
        }
        if (!$this->workflow->can($order, 'pay')) {
            // The invoice and this log retain reconciliation evidence without
            // changing an order whose workflow rejects this payment.
            $this->logger->critical('Invoice was paid while its Trade order could not transition to paid.', [
                'invoice' => $invoice->getOutTradeNo(),
                'order' => $order->getUuid(),
                'orderStatus' => $order->getStatus(),
            ]);
            return;
        }
        $order->setInvoiceId($invoice->getUuid());
        $order->setInvoiceNo($invoice->getOutTradeNo());
        $order->setPaymentStatus($invoice->getStatus());
        $order->setPaymentMethod($invoice->getPayment());
        $order->setPaidAt($invoice->getPaidAt() ?? new \DateTimeImmutable());
        $this->workflow->apply($order, 'pay');
        $this->orderService->update($order, []);
    }

    public function onInvoiceRefunded(InvoiceRefundedEvent $event): void
    {
        $invoice = $event->getInvoice();
        $order = $this->findOrder($invoice);
        if (!$order instanceof Order) {
            return;
        }

        $order->setPaymentStatus($invoice->getStatus());
        if ($invoice->getStatus() === Invoice::STATUS_REFUNDED && $this->workflow->can($order, 'refund')) {
            $order->setRefundedAt($invoice->getRefundedAt() ?? new \DateTimeImmutable());
            $this->workflow->apply($order, 'refund');
        }
        $this->orderService->update($order, []);
    }

    public function onInvoiceCancelled(InvoiceCancelledEvent $event): void
    {
        $invoice = $event->getInvoice();
        $order = $this->findOrder($invoice);
        if (!$order instanceof Order) {
            return;
        }

        $order->setPaymentStatus($invoice->getStatus());
        $this->orderService->update($order, []);
    }

    public function onInvoiceFailed(InvoiceFailedEvent $event): void
    {
        $invoice = $event->getInvoice();
        $order = $this->findOrder($invoice);
        if (!$order instanceof Order) {
            return;
        }

        $order->setPaymentStatus($invoice->getStatus());
        $this->orderService->update($order, []);
    }

    private function findOrder(Invoice $invoice): ?Order
    {
        if ($invoice->getSourceType() !== 'trade_order') {
            return null;
        }

        $order = $this->orderService->get(['uuid' => $invoice->getSourceId()]);
        return $order instanceof Order ? $order : null;
    }
}
