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
        /** @phpstan-ignore property.onlyWritten */
        private OrderServiceInterface $orderService,
        /** @phpstan-ignore property.onlyWritten */
        #[Target('state_machine.order')]
        private readonly ?WorkflowInterface $workflow = null,
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
            });

            return;
        }

        if ($this->lifecycleService !== null) {
            $storeOrderUuid = is_string($payload['storeOrderUuid'] ?? null) ? $payload['storeOrderUuid'] : null;
            $this->lifecycleService->markAccepted($orderUuid, $storeUuid, $storeOrderUuid);
        }
    }
}
