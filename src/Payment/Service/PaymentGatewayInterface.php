<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface PaymentGatewayInterface
{
    public static function getName(): string;
    /** @param array<string, mixed> $options */
    public function pay(Invoice $invoice, int $amount, array $options = []): PaymentResult;
    public function notify(Request $request): PaymentNotifyResult;
    /**
     * @param array<string, mixed> $options Includes a stable `refundIdempotencyKey`.
     * Gateways must use that key (or provide equivalent provider-side idempotency)
     * so an uncertain response can be retried without issuing a second refund.
     */
    public function refund(Invoice $invoice, int $amount, int $paidAmount, string $reason, array $options = []): PaymentRefundResult;
    public function getNotifySuccessResponse(PaymentNotifyResult $result): Response;
}
