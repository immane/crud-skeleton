<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Trade\EventListener;

use App\Trade\Entity\Order;
use App\Trade\EventListener\OrderWorkflowListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderWorkflowListenerTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $events = OrderWorkflowListener::getSubscribedEvents();

        self::assertArrayHasKey('workflow.order.transition', $events);
        self::assertSame('onTransition', $events['workflow.order.transition']);
    }

    public function testCancelTransitionSetsCancelledAt(): void
    {
        $order = new Order();
        self::assertNull($order->getCancelledAt());

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'cancel');

        $listener->onTransition($event);

        self::assertNotNull($order->getCancelledAt());
    }

    public function testCompleteTransitionSetsCompletedAt(): void
    {
        $order = new Order();
        self::assertNull($order->getCompletedAt());

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'complete');

        $listener->onTransition($event);

        self::assertNotNull($order->getCompletedAt());
    }

    public function testPayTransitionSetsPaidAt(): void
    {
        $order = new Order();
        self::assertNull($order->getPaidAt());

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'pay');

        $listener->onTransition($event);

        self::assertNotNull($order->getPaidAt());
    }

    public function testPayTransitionPreservesExistingPaidAt(): void
    {
        $order = new Order();
        $existingPaidAt = new \DateTimeImmutable('2025-01-01');
        $order->setPaidAt($existingPaidAt);

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'pay');

        $listener->onTransition($event);

        self::assertSame($existingPaidAt, $order->getPaidAt());
    }

    public function testFulfillTransitionSetsFulfilledAt(): void
    {
        $order = new Order();
        self::assertNull($order->getFulfilledAt());

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'fulfill');

        $listener->onTransition($event);

        self::assertNotNull($order->getFulfilledAt());
    }

    public function testFulfillTransitionPreservesExistingFulfilledAt(): void
    {
        $order = new Order();
        $existingAt = new \DateTimeImmutable('2025-02-01');
        $order->setFulfilledAt($existingAt);

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'fulfill');

        $listener->onTransition($event);

        self::assertSame($existingAt, $order->getFulfilledAt());
    }

    public function testRefundTransitionSetsRefundedAt(): void
    {
        $order = new Order();
        self::assertNull($order->getRefundedAt());

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'refund');

        $listener->onTransition($event);

        self::assertNotNull($order->getRefundedAt());
    }

    public function testRefundTransitionPreservesExistingRefundedAt(): void
    {
        $order = new Order();
        $existingAt = new \DateTimeImmutable('2025-03-01');
        $order->setRefundedAt($existingAt);

        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'refund');

        $listener->onTransition($event);

        self::assertSame($existingAt, $order->getRefundedAt());
    }

    public function testSubmitTransitionDoesNotThrow(): void
    {
        $order = new Order();
        $listener = $this->createListener();
        $event = $this->createTransitionEvent($order, 'submit');

        $listener->onTransition($event);

        self::assertSame('draft', $order->getStatus());
    }

    private function createListener(): OrderWorkflowListener
    {
        $logger = $this->createMock(LoggerInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        return new OrderWorkflowListener($logger, $dispatcher);
    }

    private function createTransitionEvent(Order $order, string $transitionName): TransitionEvent
    {
        $transition = new Transition($transitionName, [], []);
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('getName')->willReturn('order');

        return new TransitionEvent($order, new Marking(), $transition, $workflow);
    }
}
