<?php

declare(strict_types=1);

namespace App\Trade\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use App\Trade\Entity\Order;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\StoreContextResolverInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/app/orders', name: 'app-orders-')]
#[IsGranted('ROLE_USER')]
class OrderController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin, CreateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['items'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['items', 'currency', 'notes', 'metadata', 'meta'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['notes', 'metadata'];

    public function __construct(
        protected readonly OrderServiceInterface $service,
        private readonly StoreContextResolverInterface $storeContextResolver,
        #[Target('state_machine.order')]
        protected readonly WorkflowInterface $orderWorkflow,
    ) {
    }

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return ['id' => -1];
        }
        return ['user' => $user];
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        $items = $content['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('Items are required.');
        }
        /** @var list<array<string, mixed>> $items */

        $storeContext = $this->storeContextResolver->resolve();
        $requestedCurrency = $content['currency'] ?? null;
        if ($storeContext !== null) {
            $currency = $storeContext->currency;
            if ($requestedCurrency !== null && strtoupper((string) $requestedCurrency) !== strtoupper($currency)) {
                throw new \InvalidArgumentException(sprintf('Currency mismatch: store %s expects %s, got %s', $storeContext->storeCode, $currency, (string) $requestedCurrency));
            }
        } else {
            $currency = $requestedCurrency ?? 'CNY';
        }
        $result = $this->service->calculatePrices($items, $currency, $storeContext?->storeCode, $content['meta'] ?? []);

        $content['__calculatedItems'] = $result->items;
        $content['__totalAmount'] = $result->totalAmount;
        $content['__currency'] = $currency;
        $content['__storeContext'] = $storeContext;
        $content['__notes'] = $content['notes'] ?? null;
        $content['__metadata'] = $content['metadata'] ?? null;

        return $content;
    }

    /**
     * @param array<string, mixed> $content
     */
    protected function processEntity(array $content, object $entity): object
    {
        if (!$entity instanceof Order) {
            return $entity;
        }

        if ($entity->getId() === null && isset($content['__calculatedItems'])) {
            $user = $this->getCurrentUser();
            $order = $this->service->createOrder(
                $content['__calculatedItems'],
                $user,
                $content['__totalAmount'] ?? 0,
                $content['__currency'] ?? 'CNY',
                $content['__notes'] ?? null,
                $content['__metadata'] ?? null,
                $content['__storeContext'] ?? null,
            );
            return $order;
        }

        return $entity;
    }

    #[Route('/quote', name: 'quote', methods: ['POST'])]
    public function quoteAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $items = $content['items'] ?? [];
        if (empty($items)) {
            return $this->warning('Items are required.', 400, '', 400);
        }

        $requestedCurrency = $content['currency'] ?? null;
        try {
            $storeContext = $this->storeContextResolver->resolve();
            if ($storeContext !== null) {
                $currency = $storeContext->currency;
                if ($requestedCurrency !== null && strtoupper((string) $requestedCurrency) !== strtoupper($currency)) {
                    throw new \InvalidArgumentException(sprintf('Currency mismatch: store %s expects %s, got %s', $storeContext->storeCode, $currency, (string) $requestedCurrency));
                }
            } else {
                $currency = $requestedCurrency ?? 'CNY';
            }
            $result = $this->service->calculatePrices($items, $currency, $storeContext?->storeCode, $content['meta'] ?? []);
            return $this->success($result, 'Quote calculated');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id}/items', name: 'items', methods: ['GET'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function itemsAction(int|string $id): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        return $this->success($order->getItems()->toArray());
    }

    #[Route('/{id}/submit', name: 'submit', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function submitAction(int|string $id): Response
    {
        return $this->applyUserOrderTransition(
            $id,
            'submit',
            'Order cannot be submitted in current status.',
            'Order submitted',
        );
    }

    #[Route('/{id}/confirm', name: 'confirm', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function confirmAction(int|string $id): Response
    {
        return $this->applyUserOrderTransition(
            $id,
            'confirm',
            'Order cannot be confirmed in current status.',
            'Order confirmed',
        );
    }

    private function applyUserOrderTransition(int|string $id, string $transition, string $invalidMessage, string $successMessage): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->orderWorkflow->can($order, $transition)) {
            return $this->warning($invalidMessage, 400, '', 400);
        }

        try {
            $this->service->wrapInTransaction(function () use ($order, $transition) {
                $this->orderWorkflow->apply($order, $transition);
            });
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($order, $successMessage);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function cancelAction(int|string $id): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->orderWorkflow->can($order, 'cancel')) {
            return $this->warning('Order cannot be cancelled in current status.', 400, '', 400);
        }

        try {
            $this->service->wrapInTransaction(function () use ($order) {
                $this->cancelLinkedInvoice($order);
                $this->orderWorkflow->apply($order, 'cancel');
            });
            return $this->success($order, 'Order cancelled');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    private function cancelLinkedInvoice(Order $order): void
    {
        $this->service->cancel($order);
    }

    #[Route('/{id}/payment', name: 'payment', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function paymentAction(Request $request, int|string $id): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->orderWorkflow->can($order, 'pay')) {
            return $this->warning('Order cannot be paid in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $payment = $content['payment'] ?? 'mock';
        if (!is_string($payment) || $payment === '') {
            $payment = 'mock';
        }

        try {
            $result = $this->service->createPayment($order, $payment, $content);
            return $this->success($result, 'Payment started');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id}/refund', name: 'refund', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function refundAction(Request $request, int|string $id): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->orderWorkflow->can($order, 'refund')) {
            return $this->warning('Order cannot be refunded in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $reason = $content['reason'] ?? '';
        if ($reason === '' || !is_string($reason)) {
            return $this->warning('reason is required.', 400, '', 400);
        }

        try {
            if ($order->getInvoiceId() !== null) {
                return $this->success($this->service->refundPayment($order, $reason, $content), 'Refund processed');
            }

            $systemWalletId = (int) ($content['systemWalletId'] ?? 0);
            if ($systemWalletId <= 0) {
                return $this->warning('systemWalletId is required.', 400, '', 400);
            }

            $this->service->wrapInTransaction(function () use ($order, $systemWalletId, $reason): void {
                $this->service->refund($order, $systemWalletId, $reason);
                $this->orderWorkflow->apply($order, 'refund');
                $this->service->update($order, []);
            });
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($order, 'Refund processed');
    }
}
