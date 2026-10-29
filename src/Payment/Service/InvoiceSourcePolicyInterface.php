<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\Entity\Invoice;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Source-owned coordination without importing source domain models into Payment. */
#[AutoconfigureTag('payment.invoice_source_policy')]
interface InvoiceSourcePolicyInterface
{
    public static function getSourceType(): string;

    /**
     * Lock and refresh the source aggregate inside Payment's transaction, before
     * the invoice lock. Use the same connection and hold locks until commit.
     */
    public function lock(Invoice $invoice): void;

    /**
     * Validate a new or resumed refund after source and invoice locks are held.
     * Completed replays bypass this check. No external side effects are allowed.
     */
    public function assertRefundable(Invoice $invoice): void;
}
