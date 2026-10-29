<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceSourcePolicyInterface;
use App\Trade\Entity\Order;
use App\Trade\Repository\OrderRepository;

final readonly class OrderInvoiceSourcePolicy implements InvoiceSourcePolicyInterface
{
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
        if (!$order instanceof Order || $order->getStatus() !== Order::STATUS_PAID
            || $order->getInvoiceId() !== $invoice->getUuid()) {
            throw new \RuntimeException('The linked order must be paid and linked to this invoice before refunding.');
        }
    }
}
