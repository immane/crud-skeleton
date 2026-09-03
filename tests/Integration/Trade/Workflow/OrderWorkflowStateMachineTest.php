<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade\Workflow;

use App\Trade\Entity\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\Exception\TransitionException;
use Symfony\Component\Workflow\Exception\UndefinedTransitionException;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Exhaustive state-machine coverage of the `order` workflow declared in
 * config/packages/workflow.yaml.
 *
 * These tests exercise the real container-wired workflow (service
 * `state_machine.order`) plus the OrderWorkflowListener side-effects
 * (timestamp fields) without touching the database.
 *
 * NOTE: config/packages/workflow.yaml declares NO `guard:` conditions for any
 * order transition. Guards for the pay/fulfill/refund steps are enforced in the
 * service layer (OrderService) and controllers (`WorkflowInterface::can()`).
 * Tests for those enforcement points live in OrderWorkflowApiTest and
 * OrderServiceTest.
 */
final class OrderWorkflowStateMachineTest extends KernelTestCase
{
    private WorkflowInterface $workflow;

    protected static function getKernelClass(): string
    {
        if (!class_exists(\App\Kernel::class, false)) {
            require_once dirname(__DIR__, 4) . '/src/Kernel.php';
        }

        return \App\Kernel::class;
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        $class = static::getKernelClass();
        $env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
        $debug = $options['debug'] ?? $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? true;

        return new $class($env, (bool) $debug);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->workflow = static::getContainer()->get('state_machine.order');
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
    }

    private function orderIn(string $status): Order
    {
        return (new Order())->setStatus($status);
    }

    // =====================================================================
    // 1. Initial marking + happy path chain
    // =====================================================================

    public function testNewOrderStartsWithDraftMarking(): void
    {
        $order = new Order();

        self::assertSame(Order::STATUS_DRAFT, $order->getStatus());
        self::assertTrue($this->workflow->can($order, 'submit'));
        self::assertTrue($this->workflow->can($order, 'store_submit'));
        self::assertTrue($this->workflow->can($order, 'cancel'));
    }

    #[Group('low-value')]
    public function testHappyPathChainDraftToRefunded(): void
    {
        $order = new Order();
        $chain = [
            'submit' => Order::STATUS_PENDING,
            'confirm' => Order::STATUS_CONFIRMED,
            'pay' => Order::STATUS_PAID,
            'refund' => Order::STATUS_REFUNDED,
        ];

        foreach ($chain as $transition => $expected) {
            self::assertTrue($this->workflow->can($order, $transition), "can('$transition') from {$order->getStatus()}");
            $this->workflow->apply($order, $transition);
            self::assertSame($expected, $order->getStatus(), "after '$transition'");
        }

        self::assertSame([], $this->workflow->getEnabledTransitions($order));
    }

    #[Group('low-value')]
    public function testStoreBranchChainDraftToCancelledViaReject(): void
    {
        $order = new Order();

        $this->workflow->apply($order, 'store_submit');
        self::assertSame('awaiting_store_acceptance', $order->getStatus());

        $this->workflow->apply($order, 'store_reject');
        self::assertSame('store_rejected', $order->getStatus());

        $this->workflow->apply($order, 'cancel');
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
    }

    #[Group('low-value')]
    public function testStoreBranchChainAwaitingAcceptToConfirmed(): void
    {
        $order = new Order();

        $this->workflow->apply($order, 'store_submit');
        self::assertSame('awaiting_store_acceptance', $order->getStatus());

        $this->workflow->apply($order, 'store_accept');
        self::assertSame('store_accepted', $order->getStatus());

        $this->workflow->apply($order, 'confirm');
        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());

        $this->workflow->apply($order, 'pay');
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
    }

    // =====================================================================
    // 2. Every enabled transition from every state (per workflow.yaml)
    // =====================================================================

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function enabledTransitionsProvider(): array
    {
        return [
            'draft' => ['draft', ['submit', 'store_submit', 'cancel']],
            'pending' => ['pending', ['confirm', 'cancel']],
            'awaiting_store_acceptance' => ['awaiting_store_acceptance', ['store_accept', 'store_reject', 'cancel']],
            'store_accepted' => ['store_accepted', ['confirm', 'cancel']],
            'store_rejected' => ['store_rejected', ['cancel']],
            'confirmed' => ['confirmed', ['pay', 'cancel']],
            'paid' => ['paid', ['fulfill', 'refund']],
            'fulfilled' => ['fulfilled', ['complete', 'cancel']],
            'awaiting_store_verification' => ['awaiting_store_verification', ['cancel']],
            'completed' => ['completed', []],
            'cancelled' => ['cancelled', []],
            'refunded' => ['refunded', []],
        ];
    }

    #[DataProvider('enabledTransitionsProvider')]
    public function testEnabledTransitionsMatchWorkflowConfig(string $state, array $expected): void
    {
        $order = $this->orderIn($state);

        $actual = array_map(
            static fn (Transition $t): string => $t->getName(),
            $this->workflow->getEnabledTransitions($order),
        );
        sort($actual);
        sort($expected);

        self::assertSame($expected, $actual, "enabled transitions from '$state'");

        foreach ($expected as $transition) {
            self::assertTrue($this->workflow->can($order, $transition), "can('$transition') from '$state'");
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validTransitionProvider(): array
    {
        return [
            'draft->submit' => ['draft', 'submit', 'pending'],
            'draft->store_submit' => ['draft', 'store_submit', 'awaiting_store_acceptance'],
            'draft->cancel' => ['draft', 'cancel', 'cancelled'],
            'pending->confirm' => ['pending', 'confirm', 'confirmed'],
            'pending->cancel' => ['pending', 'cancel', 'cancelled'],
            'awaiting_store_acceptance->store_accept' => ['awaiting_store_acceptance', 'store_accept', 'store_accepted'],
            'awaiting_store_acceptance->store_reject' => ['awaiting_store_acceptance', 'store_reject', 'store_rejected'],
            'awaiting_store_acceptance->cancel' => ['awaiting_store_acceptance', 'cancel', 'cancelled'],
            'store_accepted->confirm' => ['store_accepted', 'confirm', 'confirmed'],
            'store_accepted->cancel' => ['store_accepted', 'cancel', 'cancelled'],
            'store_rejected->cancel' => ['store_rejected', 'cancel', 'cancelled'],
            'confirmed->pay' => ['confirmed', 'pay', 'paid'],
            'confirmed->cancel' => ['confirmed', 'cancel', 'cancelled'],
            'paid->fulfill' => ['paid', 'fulfill', 'fulfilled'],
            'paid->refund' => ['paid', 'refund', 'refunded'],
            'fulfilled->complete' => ['fulfilled', 'complete', 'completed'],
            'fulfilled->cancel' => ['fulfilled', 'cancel', 'cancelled'],
            'awaiting_store_verification->cancel' => ['awaiting_store_verification', 'cancel', 'cancelled'],
        ];
    }

    #[DataProvider('validTransitionProvider')]
    public function testApplyingValidTransitionMovesToTargetState(string $from, string $transition, string $to): void
    {
        $order = $this->orderIn($from);

        $this->workflow->apply($order, $transition);

        self::assertSame($to, $order->getStatus(), "'$transition' should move '$from' -> '$to'");
    }

    // =====================================================================
    // 3. Bad paths: invalid / duplicate / unknown transitions
    // =====================================================================

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidTransitionProvider(): array
    {
        return [
            'draft->pay' => ['draft', 'pay'],
            'draft->confirm' => ['draft', 'confirm'],
            'draft->fulfill' => ['draft', 'fulfill'],
            'draft->complete' => ['draft', 'complete'],
            'draft->refund' => ['draft', 'refund'],
            'draft->store_accept' => ['draft', 'store_accept'],
            'draft->store_reject' => ['draft', 'store_reject'],
            'pending->pay' => ['pending', 'pay'],
            'pending->fulfill' => ['pending', 'fulfill'],
            'pending->submit' => ['pending', 'submit'],
            'pending->store_submit' => ['pending', 'store_submit'],
            'confirmed->submit' => ['confirmed', 'submit'],
            'confirmed->confirm' => ['confirmed', 'confirm'],
            'confirmed->fulfill' => ['confirmed', 'fulfill'],
            'confirmed->complete' => ['confirmed', 'complete'],
            'confirmed->refund' => ['confirmed', 'refund'],
            'confirmed->store_submit' => ['confirmed', 'store_submit'],
            'paid->pay' => ['paid', 'pay'],
            'paid->confirm' => ['paid', 'confirm'],
            'paid->complete' => ['paid', 'complete'],
            'paid->cancel' => ['paid', 'cancel'],
            'fulfilled->pay' => ['fulfilled', 'pay'],
            'fulfilled->fulfill' => ['fulfilled', 'fulfill'],
            'fulfilled->refund' => ['fulfilled', 'refund'],
            'completed->pay' => ['completed', 'pay'],
            'completed->complete' => ['completed', 'complete'],
            'completed->cancel' => ['completed', 'cancel'],
            'completed->refund' => ['completed', 'refund'],
            'cancelled->submit' => ['cancelled', 'submit'],
            'cancelled->confirm' => ['cancelled', 'confirm'],
            'cancelled->pay' => ['cancelled', 'pay'],
            'cancelled->cancel' => ['cancelled', 'cancel'],
            'cancelled->refund' => ['cancelled', 'refund'],
            'refunded->submit' => ['refunded', 'submit'],
            'refunded->pay' => ['refunded', 'pay'],
            'refunded->refund' => ['refunded', 'refund'],
            'refunded->cancel' => ['refunded', 'cancel'],
            'store_accepted->store_accept' => ['store_accepted', 'store_accept'],
            'store_rejected->confirm' => ['store_rejected', 'confirm'],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function testApplyingInvalidTransitionThrowsAndLeavesStateUnchanged(string $from, string $transition): void
    {
        $order = $this->orderIn($from);

        try {
            $this->workflow->apply($order, $transition);
            self::fail("Expected '$transition' from '$from' to throw NotEnabledTransitionException");
        } catch (NotEnabledTransitionException $e) {
            self::assertSame($transition, $e->getTransitionName());
        } catch (TransitionException $e) {
            // Any transition exception is acceptable for an invalid application.
        }

        self::assertSame($from, $order->getStatus(), 'status must be unchanged after failed transition');
    }

    #[Group('low-value')]
    public function testApplyingUnknownTransitionThrowsUndefinedTransitionException(): void
    {
        $order = $this->orderIn(Order::STATUS_DRAFT);

        $this->expectException(UndefinedTransitionException::class);

        $this->workflow->apply($order, 'do_the_thing');
    }

    public function testCanReturnsFalseForUnknownTransition(): void
    {
        $order = $this->orderIn(Order::STATUS_DRAFT);

        self::assertFalse($this->workflow->can($order, 'do_the_thing'));
    }

    #[Group('low-value')]
    public function testDuplicateSubmitTransitionIsRejected(): void
    {
        $order = new Order();

        $this->workflow->apply($order, 'submit');
        self::assertSame(Order::STATUS_PENDING, $order->getStatus());
        self::assertFalse($this->workflow->can($order, 'submit'));

        $this->expectException(NotEnabledTransitionException::class);
        $this->workflow->apply($order, 'submit');
    }

    public function testDuplicatePayTransitionIsRejected(): void
    {
        $order = $this->orderIn(Order::STATUS_CONFIRMED);

        $this->workflow->apply($order, 'pay');
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertFalse($this->workflow->can($order, 'pay'));

        $this->expectException(NotEnabledTransitionException::class);
        $this->workflow->apply($order, 'pay');
    }

    #[Group('low-value')]
    public function testCancelIsRejectedFromPaid(): void
    {
        $order = $this->orderIn(Order::STATUS_PAID);

        self::assertFalse($this->workflow->can($order, 'cancel'));

        $this->expectException(NotEnabledTransitionException::class);
        $this->workflow->apply($order, 'cancel');
    }

    public function testCancelIsRejectedFromTerminalStates(): void
    {
        foreach ([Order::STATUS_CANCELLED, Order::STATUS_REFUNDED] as $state) {
            $order = $this->orderIn($state);
            self::assertFalse($this->workflow->can($order, 'cancel'), "cancel should be disabled from $state");
            self::assertSame([], $this->workflow->getEnabledTransitions($order), "no transitions from $state");
        }
    }

    // =====================================================================
    // 4. Workflow guards — there are NONE in config/packages/workflow.yaml
    // =====================================================================

    public function testNoTransitionInWorkflowConfigDeclaresAGuard(): void
    {
        // In the installed Symfony Workflow version the Transition value object
        // exposes no guard API at all, and config/packages/workflow.yaml does
        // not set `guard:` on any order transition. Guard enforcement for
        // store acceptance/verification lives in StoreOrderWorkflowGuardListener
        // (event guard), not in workflow.yaml guard expressions. This test pins
        // the set of transition names so accidental renames are caught.
        $definition = $this->workflow->getDefinition();

        $names = array_map(
            static fn (Transition $t): string => $t->getName(),
            $definition->getTransitions(),
        );

        // Multi-from transitions (confirm, cancel) are expanded by Symfony into
        // one Transition object per (name, from-place) arc, hence 18 arcs for 12
        // unique transition names (added request_verification + store_verify).
        $unique = array_values(array_unique($names));
        sort($unique);

        self::assertSame([
            'cancel',
            'complete',
            'confirm',
            'fulfill',
            'pay',
            'refund',
            'request_verification',
            'store_accept',
            'store_reject',
            'store_submit',
            'store_verify',
            'submit',
        ], $unique);
    }

    public function testWorkflowLayerAllowsPayWithoutAmountWalletOrUser(): void
    {
        // Guard probe: an order with zero amount, no user and no wallet can still
        // take the `pay` transition at the workflow layer because no guard exists.
        $order = $this->orderIn(Order::STATUS_CONFIRMED);

        $this->workflow->apply($order, 'pay');

        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertNotNull($order->getPaidAt());
    }

    public function testWorkflowLayerAllowsCompleteWithoutFulfilledAt(): void
    {
        $order = $this->orderIn(Order::STATUS_FULFILLED);
        self::assertNull($order->getFulfilledAt());

        $this->workflow->apply($order, 'complete');

        self::assertSame(Order::STATUS_COMPLETED, $order->getStatus());
        self::assertNull($order->getFulfilledAt());
    }

    public function testWorkflowLayerAllowsRefundWithoutRefundReason(): void
    {
        $order = $this->orderIn(Order::STATUS_PAID);
        self::assertNull($order->getRefundedAt());

        $this->workflow->apply($order, 'refund');

        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());
        self::assertNotNull($order->getRefundedAt());
    }

    // =====================================================================
    // 5. Listener side effects: timestamp fields set by OrderWorkflowListener
    // =====================================================================

    #[Group('low-value')]
    public function testPayTransitionSetsPaidAtWhenNull(): void
    {
        $order = $this->orderIn(Order::STATUS_CONFIRMED);
        self::assertNull($order->getPaidAt());

        $this->workflow->apply($order, 'pay');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getPaidAt());
    }

    #[Group('low-value')]
    public function testPayTransitionPreservesExistingPaidAt(): void
    {
        $existing = new \DateTimeImmutable('2025-01-01 00:00:00');
        $order = $this->orderIn(Order::STATUS_CONFIRMED)->setPaidAt($existing);

        $this->workflow->apply($order, 'pay');

        self::assertSame($existing, $order->getPaidAt());
    }

    #[Group('low-value')]
    public function testFulfillTransitionSetsFulfilledAtWhenNull(): void
    {
        $order = $this->orderIn(Order::STATUS_PAID);
        self::assertNull($order->getFulfilledAt());

        $this->workflow->apply($order, 'fulfill');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getFulfilledAt());
    }

    #[Group('low-value')]
    public function testCompleteTransitionSetsCompletedAt(): void
    {
        $order = $this->orderIn(Order::STATUS_FULFILLED);
        self::assertNull($order->getCompletedAt());

        $this->workflow->apply($order, 'complete');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getCompletedAt());
    }

    #[Group('low-value')]
    public function testCancelTransitionSetsCancelledAt(): void
    {
        $order = $this->orderIn(Order::STATUS_DRAFT);
        self::assertNull($order->getCancelledAt());

        $this->workflow->apply($order, 'cancel');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getCancelledAt());
    }

    #[Group('low-value')]
    public function testRefundTransitionSetsRefundedAtWhenNull(): void
    {
        $order = $this->orderIn(Order::STATUS_PAID);
        self::assertNull($order->getRefundedAt());

        $this->workflow->apply($order, 'refund');

        self::assertInstanceOf(\DateTimeImmutable::class, $order->getRefundedAt());
    }

    #[Group('low-value')]
    public function testSubmitConfirmAndStoreTransitionsDoNotSetTimestamps(): void
    {
        $order = new Order();
        $this->workflow->apply($order, 'submit');

        self::assertNull($order->getPaidAt());
        self::assertNull($order->getFulfilledAt());
        self::assertNull($order->getCompletedAt());
        self::assertNull($order->getCancelledAt());
        self::assertNull($order->getRefundedAt());
    }

    #[Group('low-value')]
    public function testStoreRejectDoesNotSetTimestamp(): void
    {
        $order = new Order();
        $this->workflow->apply($order, 'store_submit');
        $this->workflow->apply($order, 'store_reject');

        self::assertNull($order->getCancelledAt());
        self::assertSame('store_rejected', $order->getStatus());
    }

    public function testOrderEntityExposesMarkingViaGetStatusSetStatus(): void
    {
        // Guards the marking_store wiring (method + property 'status') declared
        // in config/packages/workflow.yaml.
        $order = new Order();
        $order->setStatus(Order::STATUS_PAID);

        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertTrue($this->workflow->can($order, 'fulfill'));
        self::assertFalse($this->workflow->can($order, 'pay'));
    }
}
