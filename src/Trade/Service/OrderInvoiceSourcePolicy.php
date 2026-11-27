<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceSourcePolicyInterface;
use App\Trade\Entity\Order;
use App\Trade\Repository\OrderRepository;

final readonly class OrderInvoiceSourcePolicy implements InvoiceSourcePolicyInterface
{
    public const REASON_ORDER_NOT_PAYABLE = 'source_order_not_payable';

    public function __construct(private OrderRepository $orders)
    {
    }

    public static function getSourceType(): string
    {
        return 'trade_order';
    }

    public function lock(Invoice $invoice): void
    {
        $this->orders->findByUuidForUpdate($invoice->getSourceId());
    }

    public function assertRefundable(Invoice $invoice): void
    {
        $order = $this->orders->findByUuidForUpdate($invoice->getSourceId());
        if ($order instanceof Order && (self::isUnappliedPayment($order, $invoice)
            || ($order->getStatus() === Order::STATUS_PAID && $order->getInvoiceId() === $invoice->getUuid()))) {
            return;
        }

        throw new \RuntimeException('The linked order must be paid and linked to this invoice before refunding.');
    }

    public static function isSettled(Order $order): bool
    {
        return in_array($order->getStatus(), [Order::STATUS_PAID, Order::STATUS_FULFILLED, Order::STATUS_COMPLETED, Order::STATUS_REFUNDED], true);
    }

    public static function isUnappliedPayment(Order $order, Invoice $invoice): bool
    {
        if (self::isSettled($order)) {
            return $order->getInvoiceId() !== null && $order->getInvoiceId() !== $invoice->getUuid();
        }

        $followup = $invoice->getExtraData()[Invoice::EXTRA_PAID_FOLLOWUP] ?? null;

        return $order->getPaidAt() === null
            && is_array($followup)
            && ($followup['status'] ?? null) === 'paid_followup_failed'
            && ($followup['reason'] ?? null) === self::REASON_ORDER_NOT_PAYABLE
            && ($followup['orderUuid'] ?? null) === $order->getUuid();
    }
}
