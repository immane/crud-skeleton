<?php

declare(strict_types=1);

namespace App\Trade\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\ApiViewMessages;
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
use Symfony\Component\Validator\Exception\ValidatorException;
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

    public function __construct(
        protected readonly OrderServiceInterface $service,
        private readonly StoreContextResolverInterface $storeContextResolver,
        #[Target('state_machine.order')]
        protected readonly WorkflowInterface $workflow,
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
     * Use CreateApiViewMixin lifecycle for order creation.
     * Validates required/accepted fields via the mixin, then handles pricing and store context.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function createAction(Request $request): Response
    {
        // Leverage CreateApiViewMixin's required/accepted filtering and hooks
        $content = json_decode($request->getContent(), true) ?: [];

        // Required/accepted filtering (mimic CreateApiViewMixin)
        try {
            $filtered = [];
            foreach ($this->requiredCreateProperties as $prop) {
                if (!array_key_exists($prop, $content)) {
                    throw new ValidatorException(ApiViewMessages::propertyRequired($prop));
                }
                $filtered[$prop] = $content[$prop];
            }
            foreach ($this->acceptedCreateProperties as $prop) {
                if (array_key_exists($prop, $content)) {
                    $filtered[$prop] = $content[$prop];
                }
            }
            $content = array_merge($filtered, $this->defaultCreateValues());
            // No @transform for orders, but keep hook for consistency
            $content = $this->processCreateContent($content, $this->service->new());
        } catch (ValidatorException $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        $items = $content['items'] ?? [];
        if (empty($items)) {
            return $this->warning('Items are required.', 400, '', 400);
        }

        $currency = $content['currency'] ?? 'CNY';
        $notes = $content['notes'] ?? null;
        $metadata = isset($content['metadata']) && is_array($content['metadata']) ? $content['metadata'] : null;
        $user = $this->getCurrentUser();

        try {
            $storeContext = $this->storeContextResolver->resolve();
            $result = $this->service->calculatePrices($items, $currency, $storeContext?->storeCode, $content['meta'] ?? []);

            $order = $this->service->createOrder(
                $result->items,
                $user,
                $result->totalAmount,
                $currency,
                $notes,
                $metadata,
                $storeContext,
            );

            $isAwaitingAcceptance = $order->getStatus() === Order::STATUS_AWAITING_STORE_ACCEPTANCE;
            $created = $this->afterCreated($order);
            return $this->success(
                $created,
                $isAwaitingAcceptance ? 'Order submitted for store acceptance' : 'Order created',
                $isAwaitingAcceptance ? 202 : 201,
            );
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/quote', name: 'quote', methods: ['POST'])]
    public function quoteAction(Request $request): Response
    {
        $content = json_decode($request->getContent(), true) ?: [];

        $items = $content['items'] ?? [];
        if (empty($items)) {
            return $this->warning('Items are required.', 400, '', 400);
        }

        $currency = $content['currency'] ?? 'CNY';

        try {
            $storeContext = $this->storeContextResolver->resolve();
            $result = $this->service->calculatePrices($items, $currency, $storeContext?->storeCode, $content['meta'] ?? []);
            return $this->success($result, 'Quote calculated');
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }
    }

    #[Route('/{id<\d+>}/items', name: 'items', methods: ['GET'])]
    public function itemsAction(int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        return $this->success($order->getItems()->toArray());
    }

    #[Route('/{id<\d+>}/submit', name: 'submit', methods: ['POST'])]
    public function submitAction(int $id): Response
    {
        return $this->applyUserOrderTransition(
            $id,
            'submit',
            'Order cannot be submitted in current status.',
            'Order submitted',
        );
    }

    #[Route('/{id<\d+>}/confirm', name: 'confirm', methods: ['POST'])]
    public function confirmAction(int $id): Response
    {
        return $this->applyUserOrderTransition(
            $id,
            'confirm',
            'Order cannot be confirmed in current status.',
            'Order confirmed',
        );
    }

    private function applyUserOrderTransition(int $id, string $transition, string $invalidMessage, string $successMessage): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->workflow->can($order, $transition)) {
            return $this->warning($invalidMessage, 400, '', 400);
        }

        $this->service->wrapInTransaction(function () use ($order, $transition) {
            $this->workflow->apply($order, $transition);
        });

        return $this->success($order, $successMessage);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    #[Route('/{id<\d+>}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancelAction(int $id): Response
    {
        $order = $this->service->get(['id' => $id]);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        $user = $this->getCurrentUser();
        if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->workflow->can($order, 'cancel')) {
            return $this->warning('Order cannot be cancelled in current status.', 400, '', 400);
        }

        try {
            $this->service->wrapInTransaction(function () use ($order) {
                $this->cancelLinkedInvoice($order);
                $this->workflow->apply($order, 'cancel');
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
}
