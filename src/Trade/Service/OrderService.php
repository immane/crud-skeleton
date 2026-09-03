<?php

declare(strict_types=1);

namespace App\Trade\Service;

use App\Core\Service\BaseService;
use App\Identity\Entity\User;
use App\Payment\DTO\CreateInvoiceRequest;
use App\Payment\DTO\PaymentRefundResult;
use App\Payment\DTO\PaymentResult;
use App\Payment\Entity\Invoice;
use App\Payment\Service\InvoiceServiceInterface;
use App\Trade\DTO\StoreContext;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use App\Trade\Service\Pricing\PriceCalculationContext;
use App\Trade\Service\Pricing\PriceCalculationResult;
use App\Trade\Service\Pricing\PriceCalculatorInterface;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\Transfer\TransferServiceInterface;
use App\Store\DTO\StoreSettings;
use App\Store\Repository\StoreRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/** @extends BaseService<\App\Trade\Entity\Order> */
final class OrderService extends BaseService implements OrderServiceInterface
{
    /**
     * @param iterable<PriceCalculatorInterface> $priceCalculators
     */
    public function __construct(
        ContainerInterface $container,
        #[AutowireIterator('trade.price_calculator')]
        private readonly iterable $priceCalculators,
        private readonly ?WalletRepository $walletRepository = null,
        private readonly ?TransferServiceInterface $transferService = null,
        private readonly ?InvoiceServiceInterface $invoiceService = null,
        private readonly ?TradeOutboxServiceInterface $outboxService = null,
        #[Target('state_machine.order')]
        private readonly ?WorkflowInterface $workflow = null,
        private ?StoreRepository $storeRepository = null,
    ) {
        parent::__construct($container, Order::class);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $meta
     */
    public function calculatePrices(array $items, string $currency = 'CNY', ?string $storeCode = null, array $meta = []): PriceCalculationResult
    {
        $context = new PriceCalculationContext($items, $currency);
        $context->user = $this->user;
        $context->storeCode = $storeCode;
        $context->meta = $meta;

        $sortedCalculators = $this->getSortedCalculators();
        foreach ($sortedCalculators as $calculator) {
            $calculator->calculate($context);
        }

        return PriceCalculationResult::fromContext($context);
    }

    /**
     * @param list<array<string, mixed>> $calculatedItems
     * @param array<string, mixed>|null  $metadata
     */
    public function createOrder(array $calculatedItems, mixed $user, int $totalAmount, string $currency = 'CNY', ?string $notes = null, ?array $metadata = null, ?StoreContext $storeContext = null): Order
    {
        return $this->wrapInTransaction(function () use ($calculatedItems, $user, $totalAmount, $currency, $notes, $metadata, $storeContext) {
            $order = new Order();
            if ($user instanceof User) {
                $order->setUser($user);
            } elseif (is_array($user) && isset($user['id'])) {
                $order->setUser($this->getEntityManager()->getReference(User::class, $user['id']));
            }
            $order->setTotalAmount($totalAmount);
            $order->setCurrency($currency);
            $order->setNotes($notes);
            if ($storeContext !== null) {
                $metadata ??= [];
                $metadata['_store'] = $storeContext->toSnapshot();
            }
            $order->setMetadata($metadata);

            foreach ($calculatedItems as $item) {
                $orderItem = new OrderItem();
                if (isset($item['specificationUuid']) && is_string($item['specificationUuid'])) {
                    $orderItem->setSpecificationUuid($item['specificationUuid']);
                } elseif (isset($item['specification']) && is_object($item['specification']) && method_exists($item['specification'], 'getUuid')) {
                    $orderItem->setSpecificationUuid($item['specification']->getUuid());
                } elseif (isset($item['specSnapshot']['uuid']) && is_string($item['specSnapshot']['uuid'])) {
                    $orderItem->setSpecificationUuid($item['specSnapshot']['uuid']);
                }
                if (isset($item['specificationName']) && is_string($item['specificationName'])) {
                    $orderItem->setSpecificationTitle($item['specificationName']);
                } elseif (isset($item['specSnapshot']['name']) && is_string($item['specSnapshot']['name'])) {
                    $orderItem->setSpecificationTitle($item['specSnapshot']['name']);
                }
                $orderItem->setQuantity($item['quantity']);
                $orderItem->setUnitPrice($item['unitPrice']);
                $orderItem->setPrice($item['price']);

                if (isset($item['specSnapshot'])) {
                    $orderItem->setSpecSnapshot($item['specSnapshot']);
                }
                if (isset($item['productSnapshot'])) {
                    $orderItem->setProductSnapshot($item['productSnapshot']);
                }

                $order->addItem($orderItem);
            }

            $this->getEntityManager()->persist($order);
            $this->getEntityManager()->flush();

            if ($storeContext !== null) {
                if ($this->workflow === null || $this->outboxService === null) {
                    throw new \RuntimeException('Store order orchestration is not configured.');
                }
                // Optional acceptance: GuardListener blocks store_submit when requireAcceptance=false
                if (!$this->workflow->can($order, 'store_submit')) {
                    // If store requires acceptance but transition blocked for other reason, throw
                    $requireAcceptance = $this->isStoreRequireAcceptance($storeContext->storeUuid);
                    if ($requireAcceptance) {
                        throw new \RuntimeException('Order cannot be submitted for store acceptance.');
                    }
                    // Acceptance disabled → leave order as draft (metadata retained), no outbox
                } else {
                    $this->workflow->apply($order, 'store_submit');
                    $this->outboxService->record('trade.order.created.v1', 'trade_order', $order->getUuid(), [
                        'orderUuid' => $order->getUuid(),
                        'store' => $storeContext->toSnapshot(),
                        'customerUserUuid' => $order->getUser()?->getUuid(),
                        'currency' => $order->getCurrency(),
                        'totalAmount' => $order->getTotalAmount(),
                        'items' => array_map(static fn (OrderItem $item): array => [
                            'lineId' => $item->getUuid(),
                            'catalogReference' => $item->getSpecificationUuid() ?? $item->getSpecSnapshot()['uuid'] ?? '',
                            'quantity' => $item->getQuantity(),
                            'unitPrice' => $item->getUnitPrice(),
                            'lineAmount' => $item->getPrice(),
                            'snapshot' => [
                                'specification' => $item->getSpecSnapshot() ?? [],
                                'product' => $item->getProductSnapshot() ?? [],
                            ],
                        ], $order->getItems()->toArray()),
                        'delivery' => is_array($metadata['delivery'] ?? null) ? $metadata['delivery'] : [],
                        'placedAt' => $order->getCreatedAt()->format(DATE_ATOM),
                    ]);
                }
            }

            return $order;
        });
    }

    public function pay(Order $order, int $systemWalletId, string $paymentMethod = 'wallet', ?string $referenceId = null): void
    {
        if ($order->getStatus() !== Order::STATUS_CONFIRMED) {
            throw new \RuntimeException(sprintf(
                'Order #%d must be in "confirmed" status to pay, current: %s',
                $order->getId() ?? 0,
                $order->getStatus(),
            ));
        }

        if ($this->walletRepository === null || $this->transferService === null) {
            throw new \RuntimeException('Wallet module is not configured. Set up wallet before processing payments.');
        }

        $user = $order->getUser();
        if ($user === null) {
            throw new \RuntimeException('Order has no associated user.');
        }

        $userId = $user->getId();
        if ($userId === null) {
            throw new \RuntimeException('User has not been persisted yet (no ID).');
        }

        $userWallet = $this->walletRepository->findByUserAndCurrency($userId, $order->getCurrency());
        if ($userWallet === null) {
            throw new \RuntimeException(sprintf(
                'No %s wallet found for user #%d.',
                $order->getCurrency(),
                $user->getId(),
            ));
        }
        $userWalletId = $userWallet->getId();
        if ($userWalletId === null) {
            throw new \RuntimeException('Wallet has not been persisted yet (no ID).');
        }

        $this->transferService->transfer(
            $userWalletId,
            $systemWalletId,
            $order->getTotalAmount(),
            $referenceId ?? 'order-pay-' . $order->getUuid(),
            sprintf('Payment for order #%d', $order->getId() ?? 0),
        );

        $order->setPaidAt(new \DateTimeImmutable());
        $order->setPaymentMethod($paymentMethod);
    }

    public function refund(Order $order, int $systemWalletId, string $reason, ?string $referenceId = null): void
    {
        if ($order->getStatus() !== Order::STATUS_PAID) {
            throw new \RuntimeException(sprintf(
                'Order #%d must be in "paid" status to refund, current: %s',
                $order->getId() ?? 0,
                $order->getStatus(),
            ));
        }

        if ($this->walletRepository === null || $this->transferService === null) {
            throw new \RuntimeException('Wallet module is not configured. Set up wallet before processing refunds.');
        }

        $user = $order->getUser();
        if ($user === null) {
            throw new \RuntimeException('Order has no associated user.');
        }

        $userId = $user->getId();
        if ($userId === null) {
            throw new \RuntimeException('User has not been persisted yet (no ID).');
        }

        $userWallet = $this->walletRepository->findByUserAndCurrency($userId, $order->getCurrency());
        if ($userWallet === null) {
            throw new \RuntimeException(sprintf(
                'No %s wallet found for user #%d.',
                $order->getCurrency(),
                $user->getId(),
            ));
        }
        $userWalletId = $userWallet->getId();
        if ($userWalletId === null) {
            throw new \RuntimeException('Wallet has not been persisted yet (no ID).');
        }

        $this->transferService->transfer(
            $systemWalletId,
            $userWalletId,
            $order->getTotalAmount(),
            $referenceId ?? 'order-refund-' . $order->getUuid(),
            sprintf('Refund for order #%d: %s', $order->getId() ?? 0, $reason),
        );

        $order->setRefundedAt(new \DateTimeImmutable());
        $order->setRefundReason($reason);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fulfill(Order $order, array $data): void
    {
        if ($order->getStatus() !== Order::STATUS_PAID) {
            throw new \RuntimeException(sprintf(
                'Order #%d must be in "paid" status to fulfill, current: %s',
                $order->getId() ?? 0,
                $order->getStatus(),
            ));
        }

        if (isset($data['trackingNumber'])) {
            $order->setTrackingNumber($data['trackingNumber']);
        }
        if (isset($data['shippingAddress'])) {
            $order->setShippingAddress($data['shippingAddress']);
        }

        $order->setFulfilledAt(new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createPayment(Order $order, string $payment = Invoice::PAYMENT_MOCK, array $options = []): PaymentResult
    {
        if ($this->invoiceService === null) {
            throw new \RuntimeException('Payment module is not configured.');
        }
        if ($order->getStatus() !== Order::STATUS_CONFIRMED) {
            throw new \RuntimeException('Only confirmed orders can start payment.');
        }

        $invoice = null;
        if ($order->getInvoiceId() !== null) {
            $invoice = $this->invoiceService->get(['uuid' => $order->getInvoiceId()]);
        }
        if (!$invoice instanceof Invoice) {
            $invoice = $this->invoiceService->createInvoice(new CreateInvoiceRequest(
                sourceType: 'trade_order',
                sourceId: $order->getUuid(),
                scene: Invoice::SCENE_ORDER,
                amount: $order->getTotalAmount(),
                currency: $order->getCurrency(),
                payer: $order->getUser(),
                subject: sprintf('Order #%d', $order->getId() ?? 0),
                description: $order->getNotes(),
                extraData: ['orderId' => $order->getId()],
            ));

            $order->setInvoiceId($invoice->getUuid());
            $order->setInvoiceNo($invoice->getOutTradeNo());
            $order->setPaymentStatus($invoice->getStatus());
            $this->update($order, []);
        }

        return $this->invoiceService->pay($invoice, $payment, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function refundPayment(Order $order, string $reason, array $options = []): PaymentRefundResult
    {
        if ($this->invoiceService === null) {
            throw new \RuntimeException('Payment module is not configured.');
        }
        $invoice = null;
        if ($order->getInvoiceId() !== null) {
            $invoice = $this->invoiceService->get(['uuid' => $order->getInvoiceId()]);
        }
        if (!$invoice instanceof Invoice) {
            throw new \RuntimeException('Order has no linked invoice.');
        }

        return $this->invoiceService->refund($invoice, $invoice->getAmount() - $invoice->getRefundedAmount(), $reason, $options);
    }

    public function cancel(Order $order): void
    {
        if ($this->invoiceService !== null && $order->getInvoiceId() !== null) {
            $invoice = $this->invoiceService->get(['uuid' => $order->getInvoiceId()]);
            if ($invoice instanceof Invoice) {
                $this->invoiceService->cancel($invoice, 'Order cancelled.');
            }
        }
    }

    /**
     * @return list<PriceCalculatorInterface>
     */
    private function getSortedCalculators(): array
    {
        $calculators = is_array($this->priceCalculators)
            ? $this->priceCalculators
            : iterator_to_array($this->priceCalculators);

        usort($calculators, function (PriceCalculatorInterface $a, PriceCalculatorInterface $b) {
            return $a::getPriority() <=> $b::getPriority();
        });

        return $calculators;
    }

    private function isStoreRequireAcceptance(string $storeUuid): bool
    {
        if (!isset($this->storeRepository)) {
            return false;
        }
        $store = $this->storeRepository->findOneBy(['uuid' => $storeUuid]);
        if ($store === null) {
            return false;
        }

        return StoreSettings::from($store->getSettings())->requireAcceptance;
    }
}
