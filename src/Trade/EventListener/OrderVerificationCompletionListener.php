<?php

declare(strict_types=1);

namespace App\Trade\EventListener;

use App\Trade\Entity\Order;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class OrderVerificationCompletionListener implements EventSubscriberInterface
{
    public function __construct(#[Target('state_machine.order')] private WorkflowInterface $workflow)
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
        if (!$order instanceof Order
            || ($order->getMetadata()['_completionMode'] ?? null) !== 'store_verification'
            || ($order->getMetadata()['_storeVerificationReceived'] ?? false) !== true) {
            return;
        }

        $order->allowCompletionFromStoreVerification();
        try {
            if ($this->workflow->can($order, 'complete')) {
                $this->workflow->apply($order, 'complete');
            }
        } finally {
            $order->disallowCompletionFromStoreVerification();
        }
    }
}
