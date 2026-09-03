<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseServiceInterface;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Service\Pricing\PriceCalculationResult;

/** @extends BaseServiceInterface<\App\Trade\Entity\Order> */
interface OrderServiceInterface extends BaseServiceInterface
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $meta
     */
    public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult;

    /**
     * @param list<array<string, mixed>> $calculatedItems
     * @param array<string, mixed>|null  $metadata
     */
    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null, ?array $metadata = null, ?StoreContext $storeContext = null): Order;

    public function refund(Order $order, int $systemWalletId, string $reason, ?string $referenceId = null): void;

    /**
     * @param array<string, mixed> $data
     */
    public function fulfill(Order $order, array $data): void;

    /**
     * @param array<string, mixed> $options
     */
    public function createPayment(Order $order, string $payment = 'mock', array $options = []): PaymentResult;

    /**
     * @param array<string, mixed> $options
     */
    public function refundPayment(Order $order, string $reason, array $options = []): PaymentRefundResult;

    public function cancel(Order $order): void;

    public function wrapInTransaction(callable $fn): mixed;
}
