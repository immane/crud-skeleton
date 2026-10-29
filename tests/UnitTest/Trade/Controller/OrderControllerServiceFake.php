<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\Controller;

use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Test double for OrderServiceInterface used by the Trade order controller
 * unit tests.
 *
 * The interface declares wrapInTransaction() only via a `@method` PHPDoc tag,
 * and PHPUnit 12 no longer generates mocks for PHPDoc-declared methods. This
 * fake therefore delegates every real interface method to a backing PHPUnit
 * mock (so `$service->method(...)` still works) while providing a real
 * wrapInTransaction() implementation that can either invoke the callback
 * (enabled via $invokeTransaction) or behave like a no-op mock.
 */
final class OrderControllerServiceFake implements OrderServiceInterface
{
    public OrderServiceInterface $delegate;

    /** When true, wrapInTransaction() invokes the callback like the real service. */
    public bool $invokeTransaction = false;
    public ?EntityManagerInterface $entityManager = null;

    public function __construct(OrderServiceInterface $delegate)
    {
        $this->delegate = $delegate;
    }

    public function get($object, bool $directly = false)
    {
        return $this->delegate->get($object, $directly);
    }

    public function list($object = null, $order = null, bool $disableRequest = true)
    {
        return $this->delegate->list($object, $order, $disableRequest);
    }

    public function new()
    {
        return $this->delegate->new();
    }

    public function update($object, ?array $data = null, bool $noFlush = false)
    {
        return $this->delegate->update($object, $data, $noFlush);
    }

    public function remove($object): bool
    {
        return $this->delegate->remove($object);
    }

    public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult
    {
        return $this->delegate->calculatePrices($items, $currency, $storeCode, $meta);
    }

    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null, ?array $metadata = null, ?StoreContext $storeContext = null): Order
    {
        return $this->delegate->createOrder($calculatedItems, $user, $totalAmount, $currency, $notes, $metadata, $storeContext);
    }

    public function pay(Order $order, int $systemWalletId, string $paymentMethod = 'wallet', ?string $referenceId = null): void
    {
        $this->delegate->pay($order, $systemWalletId, $paymentMethod, $referenceId);
    }

    public function refund(Order $order, int $systemWalletId, string $reason, ?string $referenceId = null): void
    {
        $this->delegate->refund($order, $systemWalletId, $reason, $referenceId);
    }

    public function fulfill(Order $order, array $data): void
    {
        $this->delegate->fulfill($order, $data);
    }

    public function createPayment(Order $order, string $payment = 'mock', array $options = []): PaymentResult
    {
        return $this->delegate->createPayment($order, $payment, $options);
    }

    public function refundPayment(Order $order, string $reason, array $options = []): PaymentRefundResult
    {
        return $this->delegate->refundPayment($order, $reason, $options);
    }

    public function cancel(Order $order): void
    {
        $this->delegate->cancel($order);
    }

    public function wrapInTransaction(callable $fn): mixed
    {
        if ($this->invokeTransaction) {
            return $fn($this->entityManager ?? throw new \LogicException('An entity manager test double is required.'));
        }

        return null;
    }
}
