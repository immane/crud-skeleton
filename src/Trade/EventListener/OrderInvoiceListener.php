<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Payment\Entity\Invoice;
use App\Payment\Event\InvoiceCancelledEvent;
use App\Payment\Event\InvoiceFailedEvent;
use App\Payment\Event\InvoicePaidEvent;
use App\Payment\Event\InvoiceRefundedEvent;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderInvoiceSourcePolicy;
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
        if ($invoice->getSourceType() !== OrderInvoiceSourcePolicy::getSourceType()) {
            return;
        }
        $order = $this->findOrder($invoice);
        if (!$order instanceof Order) {
            $this->recordPaidFollowup($invoice, null, 'source_order_missing');
            return;
        }
        if (OrderInvoiceSourcePolicy::isSettled($order)) {
            if ($order->getInvoiceId() !== $invoice->getUuid()) {
                $this->recordPaidFollowup($invoice, $order, $order->getInvoiceId() === null
                    ? 'settled_invoice_missing' : 'duplicate_collection');
            }
            return;
        }
        if ($invoice->getAmount() !== $order->getTotalAmount() || $invoice->getCurrency() !== $order->getCurrency()) {
            $this->recordPaidFollowup($invoice, $order, 'source_order_payment_mismatch');
            return;
        }
        if (!$this->workflow->can($order, 'pay')) {
            $this->recordPaidFollowup($invoice, $order, OrderInvoiceSourcePolicy::REASON_ORDER_NOT_PAYABLE);
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
        if (!$order instanceof Order || $order->getInvoiceId() !== $invoice->getUuid()
            || OrderInvoiceSourcePolicy::isUnappliedPayment($order, $invoice)) {
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

    private function recordPaidFollowup(Invoice $invoice, ?Order $order, string $reason): void
    {
        $details = [
            'status' => 'paid_followup_failed',
            'reason' => $reason,
            'orderUuid' => $invoice->getSourceId(),
            'orderStatus' => $order?->getStatus(),
            'linkedInvoiceUuid' => $order?->getInvoiceId(),
            'recordedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $invoice->appendExtraData(Invoice::EXTRA_PAID_FOLLOWUP, $details);
        $this->logger->critical('A paid invoice could not be applied to its Trade order.', [
            ...$details,
            'invoiceUuid' => $invoice->getUuid(),
            'outTradeNo' => $invoice->getOutTradeNo(),
        ]);
    }
}
