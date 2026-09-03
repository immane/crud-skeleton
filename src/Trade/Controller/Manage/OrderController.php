<?php

declare(strict_types=1);

namespace App\Trade\Controller\Manage;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use App\Core\View\WorkflowApiViewMixin;
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

#[Route('/manage/orders', name: 'manage-orders-')]
#[IsGranted('ROLE_ADMIN')]
class OrderController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin, WorkflowApiViewMixin;

    protected string $workflow = 'state_machine.order';

    /** @var list<string> */
    protected array $requiredCreateProperties = ['items'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['items', 'currency', 'notes', 'metadata', 'user', 'meta'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['notes', 'metadata'];

    public function __construct(
        protected readonly OrderServiceInterface $service,
        private readonly StoreContextResolverInterface $storeContextResolver,
        #[Target('state_machine.order')]
        protected readonly WorkflowInterface $orderWorkflow,
    ) {
    }

    /** @param array<string, mixed>|null $content */
    protected function beforeTransition(string $transition, object $entity, ?array $content): void
    {
        if ($transition === 'cancel' && $entity instanceof Order) {
            $this->service->cancel($entity);
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        $items = $content['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new ValidatorException('Items are required.');
        }
        /** @var list<array<string, mixed>> $items */

        $currency = $content['currency'] ?? 'CNY';
        $storeContext = $this->storeContextResolver->resolve();
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
            $user = isset($content['user']) ? ['id' => (int) $content['user']] : null;
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

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if ($entity instanceof Order && $entity->getStatus() !== Order::STATUS_DRAFT) {
            throw new ValidatorException('Only draft orders can be updated.');
        }
        $allowed = ['notes', 'metadata'];
        $filtered = [];
        foreach ($allowed as $prop) {
            if (array_key_exists($prop, $content)) {
                $filtered[$prop] = $content[$prop];
            }
        }
        return $filtered;
    }

    protected function processDeletion(object $entity): ?Response
    {
        if ($entity instanceof Order && $entity->getStatus() !== Order::STATUS_DRAFT) {
            return $this->warning('Only draft orders can be deleted.', 400, '', 400);
        }
        return null;
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

    #[Route('/{id}/items', name: 'items', methods: ['GET'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function itemsAction(int|string $id): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        return $this->success($order->getItems()->toArray());
    }

    #[Route('/{id}/fulfill', name: 'fulfill', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function fulfillAction(Request $request, int|string $id): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->orderWorkflow->can($order, 'fulfill')) {
            return $this->warning('Order cannot be fulfilled in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];

        try {
            $this->service->wrapInTransaction(function () use ($order, $content) {
                $this->service->fulfill($order, $content);
                $this->orderWorkflow->apply($order, 'fulfill');
                $this->service->update($order, []);
            });
        } catch (\Throwable $e) {
            return $this->warning($e->getMessage(), 400, '', 400);
        }

        return $this->success($order, 'Order fulfilled');
    }

    #[Route('/{id}/refund', name: 'refund', methods: ['POST'], requirements: ['id' => '\d+|[0-9a-fA-F-]{36}'])]
    public function refundAction(Request $request, int|string $id): Response
    {
        $order = $this->service->get($this->mixIdToCommonFilter($id), false);

        if (!$order) {
            return $this->warning('Order not found.', 404, '', 404);
        }

        if (!$this->orderWorkflow->can($order, 'refund')) {
            return $this->warning('Order cannot be refunded in current status.', 400, '', 400);
        }

        $content = json_decode($request->getContent(), true) ?: [];
        $systemWalletId = (int) ($content['systemWalletId'] ?? 0);
        $reason = $content['reason'] ?? '';

        if ($reason === '') {
            return $this->warning('reason is required.', 400, '', 400);
        }

        try {
            if ($order->getInvoiceId() !== null) {
                return $this->success($this->service->refundPayment($order, $reason, $content), 'Refund processed');
            }

            if ($systemWalletId <= 0) {
                return $this->warning('systemWalletId is required.', 400, '', 400);
            }

            $this->service->wrapInTransaction(function () use ($order, $systemWalletId, $reason) {
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
