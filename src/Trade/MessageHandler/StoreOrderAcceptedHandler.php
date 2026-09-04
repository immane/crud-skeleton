<?php

declare(strict_types=1);

namespace App\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Entity\TradeConsumedEvent;
use App\Trade\Message\StoreOrderAcceptedMessage;
use App\Trade\Repository\TradeConsumedEventRepository;
use App\Trade\Service\OrderServiceInterface;
use App\Trade\Service\OrderStoreLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class StoreOrderAcceptedHandler
{
    public function __construct(
        private OrderServiceInterface $orderService,
        #[Target('state_machine.order')]
        private WorkflowInterface $workflow,
        private readonly ?TradeConsumedEventRepository $consumedRepository = null,
        private readonly ?OrderStoreLifecycleService $lifecycleService = null,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    public function __invoke(StoreOrderAcceptedMessage $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        $orderUuid = is_array($payload) ? ($payload['orderUuid'] ?? null) : null;
        $storeUuid = is_array($payload) ? ($payload['storeUuid'] ?? null) : null;
        if (!is_string($orderUuid) || !is_string($storeUuid)) {
            throw new \InvalidArgumentException('Invalid store.order.accepted.v1 envelope.');
        }

        $eventId = $message->envelope['eventId'] ?? null;
        if (is_string($eventId) && $this->consumedRepository !== null && $this->entityManager !== null) {
            if ($this->consumedRepository->findOneByEventId($eventId) !== null) {
                return;
            }
            $this->entityManager->wrapInTransaction(function () use ($eventId, $message, $payload, $orderUuid, $storeUuid): void {
                if ($this->consumedRepository->findOneByEventId($eventId) !== null) {
                    return;
                }
                $this->entityManager->persist(new TradeConsumedEvent(
                    $eventId,
                    'store.order.accepted.v1',
                    $orderUuid,
                    hash('sha256', json_encode($message->envelope, JSON_THROW_ON_ERROR)),
                ));
                if ($this->lifecycleService !== null) {
                    $storeOrderUuid = is_string($payload['storeOrderUuid'] ?? null) ? $payload['storeOrderUuid'] : null;
                    $this->lifecycleService->markAccepted($orderUuid, $storeUuid, $storeOrderUuid);
                }
                $this->applyStoreAccept($orderUuid, $storeUuid);
            });

            return;
        }

        if ($this->lifecycleService !== null) {
            $storeOrderUuid = is_string($payload['storeOrderUuid'] ?? null) ? $payload['storeOrderUuid'] : null;
            $this->lifecycleService->markAccepted($orderUuid, $storeUuid, $storeOrderUuid);
        }
        $this->applyStoreAccept($orderUuid, $storeUuid);
    }

    private function applyStoreAccept(string $orderUuid, string $storeUuid): void
    {
        $order = $this->orderService->get(['uuid' => $orderUuid]);
        if (!$order instanceof Order || ($order->getMetadata()['_store']['uuid'] ?? null) !== $storeUuid) {
            return;
        }
        if ($this->workflow->can($order, 'store_accept')) {
            $this->orderService->wrapInTransaction(function () use ($order): void {
                $this->workflow->apply($order, 'store_accept');
            });
        }
    }
}
