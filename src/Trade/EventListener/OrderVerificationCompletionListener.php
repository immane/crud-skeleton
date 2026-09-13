<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use App\Trade\Repository\OrderStoreLifecycleRepository;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class OrderVerificationCompletionListener implements EventSubscriberInterface
{
    public function __construct(
        #[Target('state_machine.order')] private WorkflowInterface $workflow,
        private OrderStoreLifecycleRepository $lifecycleRepository,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['workflow.order.completed.fulfill' => 'completeVerifiedOrder'];
    }

    /** @param CompletedEvent<Order> $event */
    public function completeVerifiedOrder(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof Order) {
            return;
        }

        $lifecycle = $this->lifecycleRepository->findOneByTradeOrderUuid($order->getUuid());
        if ($lifecycle?->getFulfillmentStatus() !== OrderStoreLifecycle::FULFILLMENT_FULFILLED
            || $lifecycle->getVerificationStatus() !== OrderStoreLifecycle::VERIFICATION_VERIFIED) {
            return;
        }
        if ($this->workflow->can($order, 'complete')) {
            $this->workflow->apply($order, 'complete');
        }
    }
}
