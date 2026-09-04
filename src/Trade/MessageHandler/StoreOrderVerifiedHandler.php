<?php

declare(strict_types=1);

namespace App\Trade\MessageHandler;

use App\Trade\Entity\Order;
use App\Trade\Message\StoreOrderVerifiedMessage;
use App\Trade\Service\OrderServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsMessageHandler]
final readonly class StoreOrderVerifiedHandler
{
    public function __construct(
        private OrderServiceInterface $orderService,
        #[Target('state_machine.order')]
        private WorkflowInterface $workflow,
    ) {
    }

    public function __invoke(StoreOrderVerifiedMessage $message): void
    {
        $payload = $message->envelope['payload'] ?? null;
        $orderUuid = is_array($payload) ? ($payload['orderUuid'] ?? null) : null;
        $storeUuid = is_array($payload) ? ($payload['storeUuid'] ?? null) : null;
        if (!is_string($orderUuid) || !is_string($storeUuid)) {
            throw new \InvalidArgumentException('Invalid store.order.verified.v1 envelope.');
        }
        $order = $this->orderService->get(['uuid' => $orderUuid]);
        if (!$order instanceof Order || ($order->getMetadata()['_store']['uuid'] ?? null) !== $storeUuid) {
            return;
        }
        if (($order->getMetadata()['_completionMode'] ?? null) !== 'store_verification') {
            return;
        }

        $this->orderService->wrapInTransaction(function () use ($order): void {
            $metadata = $order->getMetadata() ?? [];
            $metadata['_storeVerificationReceived'] = true;
            $order->setMetadata($metadata);
            $order->allowCompletionFromStoreVerification();
            try {
                if ($this->workflow->can($order, 'complete')) {
                    $this->workflow->apply($order, 'complete');
                }
            } finally {
                $order->disallowCompletionFromStoreVerification();
            }
        });
    }
}
