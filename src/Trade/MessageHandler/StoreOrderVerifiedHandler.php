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
        $verificationCode = is_array($payload) ? ($payload['verificationCode'] ?? null) : null;
        if (!is_string($orderUuid) || !is_string($storeUuid) || !is_string($verificationCode) || trim($verificationCode) === '') {
            throw new \InvalidArgumentException('Invalid store.order.verified.v1 envelope.');
        }
        $order = $this->orderService->get(['uuid' => $orderUuid]);
        if (!$order instanceof Order || ($order->getMetadata()['_store']['uuid'] ?? null) !== $storeUuid) {
            return;
        }

        $this->orderService->wrapInTransaction(function () use ($order): void {
            // If verification flow enabled, order should be in fulfilled -> awaiting_store_verification -> completed
            // Auto-move fulfilled -> awaiting_store_verification if needed before store_verify
            if ($this->workflow->can($order, 'request_verification')) {
                $this->workflow->apply($order, 'request_verification');
            }
            if ($this->workflow->can($order, 'store_verify')) {
                $this->workflow->apply($order, 'store_verify');
            }
        });
    }
}
