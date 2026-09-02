<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade;

use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Entity\Order;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end coverage of the order state machine through the HTTP API:
 * manage pay/fulfill/refund/todo/transitions/do/{transition}, the app
 * submit/confirm/cancel endpoints, guard rejections and bad paths.
 *
 * The `order` workflow declared in config/packages/workflow.yaml carries no
 * `guard:` rules; guards are enforced by the controllers' `can()` checks and
 * OrderService status checks. Those guard paths are exercised here.
 */
final class OrderWorkflowApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    // =====================================================================
    // Happy path — full chain via /do/{transition}
    // =====================================================================

    public function testHappyPathDraftToRefundedViaDoTransitions(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        $chain = [
            'submit' => Order::STATUS_PENDING,
            'confirm' => Order::STATUS_CONFIRMED,
            'pay' => Order::STATUS_PAID,
            'fulfill' => Order::STATUS_FULFILLED,
            'complete' => Order::STATUS_COMPLETED,
            'refund' => Order::STATUS_REFUNDED,
        ];

        foreach ($chain as $transition => $expectedStatus) {
            [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/{$transition}");
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), "do/{$transition}");
            self::assertSame(0, $content['code'], "do/{$transition}: " . ($content['message'] ?? ''));
        }

        [$response, $content] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(Order::STATUS_REFUNDED, $content['data']['status']);

        foreach (['paidAt', 'fulfilledAt', 'completedAt', 'refundedAt'] as $timestamp) {
            self::assertNotNull($content['data'][$timestamp], "$timestamp must be set");
        }
        self::assertNull($content['data']['cancelledAt']);
    }

    // =====================================================================
    // Store branch
    // =====================================================================

    public function testStoreBranchAcceptThenConfirmViaDoTransitions(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        $this->doTransitionOk($orderId, 'store_submit', 'awaiting_store_acceptance');
        $this->doTransitionOk($orderId, 'store_accept', 'store_accepted');
        $this->doTransitionOk($orderId, 'confirm', Order::STATUS_CONFIRMED);
        $this->doTransitionOk($orderId, 'pay', Order::STATUS_PAID);
    }

    public function testStoreBranchRejectThenCancelViaDoTransitions(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        $this->doTransitionOk($orderId, 'store_submit', 'awaiting_store_acceptance');
        $this->doTransitionOk($orderId, 'store_reject', 'store_rejected');
        $this->doTransitionOk($orderId, 'cancel', Order::STATUS_CANCELLED);
    }

    public function testStoreAcceptIsRejectedFromDraft(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/store_accept");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotSame(0, $content['code']);
    }

    // =====================================================================
    // Cancel from every cancellable state + rejection after paid
    // =====================================================================

    public function testCancelFromEveryCancellableState(): void
    {
        $specId = $this->createSpecification($this->createProduct());

        $cases = [
            'draft' => [],
            'pending' => ['submit'],
            'awaiting_store_acceptance' => ['store_submit'],
            'store_accepted' => ['store_submit', 'store_accept'],
            'store_rejected' => ['store_submit', 'store_reject'],
            'confirmed' => ['submit', 'confirm'],
        ];

        foreach ($cases as $label => $transitions) {
            $orderId = $this->createOrder($specId);
            foreach ($transitions as $transition) {
                $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/{$transition}");
            }

            [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/cancel");
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), "cancel from $label");
            self::assertSame(0, $content['code'], "cancel from $label");

            [, $detail] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
            self::assertSame(Order::STATUS_CANCELLED, $detail['data']['status'], "status after cancel from $label");
            self::assertNotNull($detail['data']['cancelledAt']);
        }
    }

    public function testCancelIsRejectedAfterPaid(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        $this->doTransitionOk($orderId, 'submit', Order::STATUS_PENDING);
        $this->doTransitionOk($orderId, 'confirm', Order::STATUS_CONFIRMED);
        $this->doTransitionOk($orderId, 'pay', Order::STATUS_PAID);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/cancel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotSame(0, $content['code'], 'cancel after paid must be rejected');

        [, $detail] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        self::assertSame(Order::STATUS_PAID, $detail['data']['status']);
    }

    // =====================================================================
    // Bad paths: duplicate transitions, unknown transitions, not found
    // =====================================================================

    public function testDuplicateSubmitViaDoIsRejectedAndStateUnchanged(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        $this->doTransitionOk($orderId, 'submit', Order::STATUS_PENDING);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/submit");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotSame(0, $content['code'], 'duplicate submit must be rejected');

        [, $detail] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        self::assertSame(Order::STATUS_PENDING, $detail['data']['status']);
    }

    public function testUnknownTransitionViaDoIsRejected(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/nonexistent");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotSame(0, $content['code']);

        [, $detail] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        self::assertSame(Order::STATUS_DRAFT, $detail['data']['status']);
    }

    public function testDoTransitionOnNotFoundOrderReturns404(): void
    {
        [$response, $content] = $this->jsonPost('/api/v1/manage/orders/999999/do/submit');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    public function testDoTransitionOnRefundedOrderIsRejected(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);
        foreach (['submit', 'confirm', 'pay', 'fulfill', 'complete', 'refund'] as $transition) {
            $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/{$transition}");
        }

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/refund");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotSame(0, $content['code'], 'refund on refunded order must be rejected');
    }

    // =====================================================================
    // Transitions endpoint
    // =====================================================================

    public function testTransitionsEndpointListsEnabledTransitionsPerState(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        $expectedByState = [
            Order::STATUS_DRAFT => ['submit', 'store_submit', 'cancel'],
            Order::STATUS_PENDING => ['confirm', 'cancel'],
            'awaiting_store_acceptance' => ['store_accept', 'store_reject', 'cancel'],
            Order::STATUS_CONFIRMED => ['pay', 'cancel'],
            Order::STATUS_PAID => ['fulfill'],
            Order::STATUS_FULFILLED => ['complete', 'cancel'],
            Order::STATUS_COMPLETED => ['refund'],
            Order::STATUS_CANCELLED => [],
            Order::STATUS_REFUNDED => [],
        ];

        foreach ($expectedByState as $state => $expectedTransitions) {
            $orderId = $this->createOrder($specId);
            $this->driveOrderTo($orderId, $state, $specId);

            [$response, $content] = $this->jsonGet("/api/v1/manage/orders/{$orderId}/transitions");
            self::assertSame(Response::HTTP_OK, $response->getStatusCode());
            self::assertSame(0, $content['code'], "transitions endpoint for $state");

            $actual = array_map(static fn (array $t): string => $t['name'], $content['data'] ?? []);
            sort($actual);
            sort($expectedTransitions);

            self::assertSame($expectedTransitions, $actual, "enabled transitions for $state");
        }
    }

    public function testTransitionsEndpointOnNotFoundReturns404(): void
    {
        [$response, $content] = $this->jsonGet('/api/v1/manage/orders/999999/transitions');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    // =====================================================================
    // Todo endpoint
    // =====================================================================

    public function testTodoEndpointReturnsOnlyOrdersWithEnabledTransitions(): void
    {
        $specId = $this->createSpecification($this->createProduct());

        $draftId = $this->createOrder($specId);
        $paidId = $this->createOrder($specId);
        $this->driveOrderTo($paidId, Order::STATUS_PAID, $specId);

        $cancelledId = $this->createOrder($specId);
        $this->driveOrderTo($cancelledId, Order::STATUS_CANCELLED, $specId);

        $refundedId = $this->createOrder($specId);
        $this->driveOrderTo($refundedId, Order::STATUS_REFUNDED, $specId);

        [$response, $content] = $this->jsonGet('/api/v1/manage/orders/todo');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $todoIds = array_map(static fn (array $o): int => (int) $o['id'], $content['data'] ?? []);

        self::assertContains($draftId, $todoIds, 'draft order has enabled transitions');
        self::assertContains($paidId, $todoIds, 'paid order has fulfill enabled');
        self::assertNotContains($cancelledId, $todoIds, 'cancelled order is terminal');
        self::assertNotContains($refundedId, $todoIds, 'refunded order is terminal');
    }

    // =====================================================================
    // Pay endpoint
    // =====================================================================

    public function testPayEndpointRejectsDraftOrder(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/pay", ['systemWalletId' => 1]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('Order cannot be paid in current status.', $content['message']);
    }

    public function testPayEndpointRequiresSystemWalletId(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);
        $this->driveOrderTo($orderId, Order::STATUS_CONFIRMED, $specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/pay", []);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('systemWalletId is required.', $content['message']);
    }

    public function testPayEndpointOnNotFoundOrderReturns404(): void
    {
        [$response, $content] = $this->jsonPost('/api/v1/manage/orders/999999/pay', ['systemWalletId' => 1]);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    public function testPayEndpointRejectsOrderWithoutWallet(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);
        $this->driveOrderTo($orderId, Order::STATUS_CONFIRMED, $specId);

        // Order has no user, so pay() aborts before any wallet lookup.
        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/pay", ['systemWalletId' => 1]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('Order has no associated user.', $content['message']);
    }

    public function testPayEndpointSuccessTransfersWalletAndMarksPaid(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        $systemUser = $this->createUser('pay-sys@example.com', ['ROLE_ADMIN']);
        $systemWallet = $this->createWallet($systemUser, 0);

        $orderId = $this->createOrder($specId, 2, $user->getId());
        $this->driveOrderTo($orderId, Order::STATUS_CONFIRMED, $specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/pay", [
            'systemWalletId' => $systemWallet->getId(),
            'paymentMethod' => 'wallet',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code'], $content['message'] ?? '');

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertNotNull($order->getPaidAt());
        self::assertSame('wallet', $order->getPaymentMethod());

        $userWallet = $this->em->getRepository(Wallet::class)->find($userWallet->getId());
        $systemWallet = $this->em->getRepository(Wallet::class)->find($systemWallet->getId());
        self::assertSame(2000, $userWallet->getBalance(), '3000 of 5000 moved to system wallet');
        self::assertSame(3000, $systemWallet->getBalance());
    }

    public function testPayEndpointRejectsSecondPayment(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $this->createWallet($user, 5000);
        $systemUser = $this->createUser('pay-sys2@example.com', ['ROLE_ADMIN']);
        $systemWallet = $this->createWallet($systemUser, 0);

        $orderId = $this->createOrder($specId, 1, $user->getId());
        $this->driveOrderTo($orderId, Order::STATUS_CONFIRMED, $specId);

        $this->jsonPost("/api/v1/manage/orders/{$orderId}/pay", ['systemWalletId' => $systemWallet->getId()]);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/pay", ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
    }

    // =====================================================================
    // Fulfill endpoint
    // =====================================================================

    public function testFulfillEndpointRejectsNonPaidOrder(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);
        $this->driveOrderTo($orderId, Order::STATUS_CONFIRMED, $specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/fulfill", ['trackingNumber' => 'TRACK-1']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('Order cannot be fulfilled in current status.', $content['message']);
    }

    public function testFulfillEndpointOnNotFoundOrderReturns404(): void
    {
        [$response, $content] = $this->jsonPost('/api/v1/manage/orders/999999/fulfill', []);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    public function testFulfillEndpointSuccessStoresTrackingAndMarksFulfilled(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);
        $this->driveOrderTo($orderId, Order::STATUS_PAID, $specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/fulfill", [
            'trackingNumber' => 'SF-8888',
            'shippingAddress' => 'No.1 Test Rd, Shanghai',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code'], $content['message'] ?? '');

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(Order::STATUS_FULFILLED, $order->getStatus());
        self::assertNotNull($order->getFulfilledAt());
        self::assertSame('SF-8888', $order->getTrackingNumber());
        self::assertSame('No.1 Test Rd, Shanghai', $order->getShippingAddress());
    }

    // =====================================================================
    // Refund endpoint
    // =====================================================================

    public function testRefundEndpointRejectsNonCompletedOrder(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);
        $this->driveOrderTo($orderId, Order::STATUS_PAID, $specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/refund", [
            'systemWalletId' => 1,
            'reason' => 'changed my mind',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('Order cannot be refunded in current status.', $content['message']);
    }

    public function testRefundEndpointRequiresReason(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);
        $this->driveOrderTo($orderId, Order::STATUS_COMPLETED, $specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/refund", ['systemWalletId' => 1]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('reason is required.', $content['message']);
    }

    public function testRefundEndpointOnNotFoundOrderReturns404(): void
    {
        [$response, $content] = $this->jsonPost('/api/v1/manage/orders/999999/refund', [
            'systemWalletId' => 1,
            'reason' => 'test',
        ]);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    public function testRefundEndpointSuccessTransfersWalletBackAndMarksRefunded(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        $systemUser = $this->createUser('refund-sys@example.com', ['ROLE_ADMIN']);
        $systemWallet = $this->createWallet($systemUser, 0);

        $orderId = $this->createOrder($specId, 2, $user->getId());
        $this->driveOrderTo($orderId, Order::STATUS_CONFIRMED, $specId);

        // Pay through the wallet so the system wallet holds the money.
        [$response] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/pay", ['systemWalletId' => $systemWallet->getId()]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->doTransitionOk($orderId, 'fulfill', Order::STATUS_FULFILLED);
        $this->doTransitionOk($orderId, 'complete', Order::STATUS_COMPLETED);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/refund", [
            'systemWalletId' => $systemWallet->getId(),
            'reason' => 'customer request',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code'], $content['message'] ?? '');

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());
        self::assertNotNull($order->getRefundedAt());
        self::assertSame('customer request', $order->getRefundReason());

        $userWallet = $this->em->getRepository(Wallet::class)->find($userWallet->getId());
        $systemWallet = $this->em->getRepository(Wallet::class)->find($systemWallet->getId());
        self::assertSame(5000, $userWallet->getBalance(), 'money returned to user wallet');
        self::assertSame(0, $systemWallet->getBalance());
    }

    // =====================================================================
    // App endpoints
    // =====================================================================

    public function testAppUserSubmitConfirmAndCancelOwnOrder(): void
    {
        $specId = $this->createSpecification($this->createProduct());

        [$response, $content] = $this->jsonPost('/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
            'currency' => 'CNY',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(Order::STATUS_DRAFT, $content['data']['status']);
        $orderId = (int) $content['data']['id'];

        [$response, $content] = $this->jsonPost("/api/v1/app/orders/{$orderId}/submit");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(Order::STATUS_PENDING, $content['data']['status']);

        [$response, $content] = $this->jsonPost("/api/v1/app/orders/{$orderId}/confirm");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(Order::STATUS_CONFIRMED, $content['data']['status']);

        [$response, $content] = $this->jsonPost("/api/v1/app/orders/{$orderId}/cancel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(Order::STATUS_CANCELLED, $content['data']['status']);
        self::assertNotNull($content['data']['cancelledAt']);
    }

    public function testAppSubmitOnAlreadySubmittedOrderIsRejected(): void
    {
        $specId = $this->createSpecification($this->createProduct());

        [, $content] = $this->jsonPost('/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = (int) $content['data']['id'];

        $this->jsonPost("/api/v1/app/orders/{$orderId}/submit");

        [$response, $content] = $this->jsonPost("/api/v1/app/orders/{$orderId}/submit");
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('Order cannot be submitted in current status.', $content['message']);
    }

    public function testAppCancelAfterPaidIsRejected(): void
    {
        $specId = $this->createSpecification($this->createProduct());

        [, $content] = $this->jsonPost('/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = (int) $content['data']['id'];
        $this->driveOrderTo($orderId, Order::STATUS_PAID, $specId);

        [$response, $content] = $this->jsonPost("/api/v1/app/orders/{$orderId}/cancel");
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(400, $content['code']);
        self::assertSame('Order cannot be cancelled in current status.', $content['message']);
    }

    public function testAppEndpointsHideOtherUsersOrders(): void
    {
        $specId = $this->createSpecification($this->createProduct());

        [, $content] = $this->jsonPost('/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
        ]);
        $orderId = (int) $content['data']['id'];

        $otherUser = $this->createUser('other-app-user@example.com', ['ROLE_USER']);

        [$response, $content] = $this->jsonPostAs($otherUser, "/api/v1/app/orders/{$orderId}/submit");
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);

        [$response, $content] = $this->jsonPostAs($otherUser, "/api/v1/app/orders/{$orderId}/cancel");
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    public function testAppSubmitOnNotFoundOrderReturns404(): void
    {
        [$response, $content] = $this->jsonPost('/api/v1/app/orders/999999/submit');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(404, $content['code']);
    }

    // =====================================================================
    // Status-reset flow (WorkflowApiViewMixin is not wired on orders)
    // =====================================================================

    public function testStatusResetRouteIsNotRegisteredOnOrderControllers(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId);

        $this->client->request('PUT', "/api/v1/manage/orders/{$orderId}/status-reset");
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), 'status-reset is dead code for orders');
    }

    // =====================================================================
    // Documented bug probes (correct behaviour asserts, currently failing)
    // =====================================================================

    public function testDoTransitionMustNotForwardArbitraryBodyFieldsToUpdate(): void
    {
        // KNOWN BUG (src/Trade/Controller/Manage/OrderController.php:329):
        // doTransitionAction() forwards the entire request body to
        // OrderService::update(), which applies ANY settable property via the
        // serializer. An admin can therefore mutate order totals through a
        // transition call, bypassing the notes/metadata whitelist of updateAction().
        $this->markTestSkipped(
            'Known bug (src/Trade/Controller/Manage/OrderController.php:329): doTransitionAction '
            . 'forwards arbitrary request fields to update(). See docs/issues/coverage-2026-08-09/trade-controllers.md.'
        );

        $specId = $this->createSpecification($this->createProduct());
        $orderId = $this->createOrder($specId, 1);

        [, $before] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        $originalTotal = $before['data']['totalAmount'];

        [$response] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/submit", [
            'totalAmount' => 1,
            'notes' => 'submitted with tampered total',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        [, $after] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        self::assertSame($originalTotal, $after['data']['totalAmount'], 'totalAmount must not be tampered via transition body');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function createProduct(): int
    {
        [$response, $content] = $this->jsonPost('/api/v1/manage/products', ['name' => 'Workflow Product', 'status' => 'active']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return (int) $content['data']['id'];
    }

    private function createSpecification(int $productId): int
    {
        [$response, $content] = $this->jsonPost("/api/v1/manage/products/{$productId}/specifications", [
            'name' => 'Workflow Spec',
            'price' => 1500,
            'status' => 'active',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return (int) $content['data']['id'];
    }

    private function createOrder(int $specId, int $quantity = 1, ?int $userId = null): int
    {
        $payload = [
            'items' => [['specificationId' => $specId, 'quantity' => $quantity]],
            'currency' => 'CNY',
        ];
        if ($userId !== null) {
            $payload['user'] = $userId;
        }

        [, $content] = $this->jsonPost('/api/v1/manage/orders', $payload);
        self::assertSame(0, $content['code'], $content['message'] ?? 'order creation failed');
        self::assertArrayHasKey('id', $content['data']);

        return (int) $content['data']['id'];
    }

    private function driveOrderTo(int $orderId, string $state, int $specId): void
    {
        $path = [
            Order::STATUS_PENDING => ['submit'],
            Order::STATUS_CONFIRMED => ['submit', 'confirm'],
            Order::STATUS_PAID => ['submit', 'confirm', 'pay'],
            Order::STATUS_FULFILLED => ['submit', 'confirm', 'pay', 'fulfill'],
            Order::STATUS_COMPLETED => ['submit', 'confirm', 'pay', 'fulfill', 'complete'],
            Order::STATUS_REFUNDED => ['submit', 'confirm', 'pay', 'fulfill', 'complete', 'refund'],
            Order::STATUS_CANCELLED => ['cancel'],
            'awaiting_store_acceptance' => ['store_submit'],
        ];

        foreach ($path[$state] as $transition) {
            $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/{$transition}");
        }

        [, $detail] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        self::assertSame($state, $detail['data']['status'] ?? null, "order {$orderId} driven to $state");
    }

    private function doTransitionOk(int $orderId, string $transition, string $expectedStatus): void
    {
        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/{$transition}");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), "do/{$transition}");
        self::assertSame(0, $content['code'], "do/{$transition}: " . ($content['message'] ?? ''));

        [, $detail] = $this->jsonGet("/api/v1/manage/orders/{$orderId}");
        self::assertSame($expectedStatus, $detail['data']['status'] ?? null, "status after do/{$transition}");
    }

    /** @return array{Response, array} */
    private function jsonPost(string $uri, array $data = []): array
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();

        return [$response, json_decode($response->getContent(), true) ?? []];
    }

    /** @return array{Response, array} */
    private function jsonPostAs(User $user, string $uri, array $data = []): array
    {
        $tokenManager = $this->client->getContainer()->get(\App\Identity\Security\TokenManager::class);
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenManager->createAccessToken($user),
        ], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();

        return [$response, json_decode($response->getContent(), true) ?? []];
    }

    /** @return array{Response, array} */
    private function jsonGet(string $uri): array
    {
        $this->client->request('GET', $uri);
        $response = $this->client->getResponse();

        return [$response, json_decode($response->getContent(), true) ?? []];
    }

    private function currentUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(strstr($email, '@', true));
        $user->setPassword($this->client->getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'TestPass123!'));
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createWallet(User $user, int $balance): Wallet
    {
        $existing = $this->em->getRepository(Wallet::class)->findOneBy(['user' => $user, 'currency' => 'CNY']);
        if ($existing instanceof Wallet) {
            $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = :balance WHERE id = :id', [
                'balance' => $balance,
                'id' => $existing->getId(),
            ]);
            $this->em->refresh($existing);

            return $existing;
        }

        $wallet = new Wallet($user, 'CNY');
        $this->em->persist($wallet);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = :balance WHERE id = :id', [
            'balance' => $balance,
            'id' => $wallet->getId(),
        ]);
        $this->em->refresh($wallet);

        return $wallet;
    }
}
