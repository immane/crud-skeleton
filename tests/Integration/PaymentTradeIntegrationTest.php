<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Entity\Order;
use App\Wallet\Entity\Wallet;
use App\Wallet\Service\Payment\PaymentDeductionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end integration coverage for the Payment <-> Trade + Wallet integration:
 * invoice lifecycle (pay/notify/cancel/refund), gateway registry, wallet balance
 * adjustment providers and the public notify webhook.
 *
 * These tests exercise cross-module paths (Payment + Trade + Wallet) through the
 * real HTTP stack (WebTestCase) on a real SQLite schema.
 */
final class PaymentTradeIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private const SPEC_PRICE = 1500;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        // Self-heal if the shared test.db was mutated between bootstrap and client boot.
        if (!$this->schemaReady($this->client->getKernel())) {
            self::$dbBootstrapped = false;
            $this->bootTestDatabase();
            self::ensureKernelShutdown();
            $this->client = static::createAuthenticatedClient();
            $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        }
    }

    /**
     * Resilient bootstrap for the shared var/test.db file (which can be mutated
     * concurrently by other tooling on this mounted volume). Retries the
     * schema drop/create until the users table is actually present.
     */
    protected function bootTestDatabase(): void
    {
        if (self::$dbBootstrapped) {
            return;
        }

        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $drop = new ArrayInput([
                'command' => 'doctrine:schema:drop',
                '--force' => true,
                '--full-database' => true,
                '--env' => 'test',
                '--quiet' => true,
            ]);
            $application->run($drop, new NullOutput());

            $create = new ArrayInput([
                'command' => 'doctrine:schema:create',
                '--env' => 'test',
                '--quiet' => true,
            ]);
            $application->run($create, new NullOutput());

            if ($this->schemaReady($kernel)) {
                break;
            }
            usleep(1_500_000);
        }

        self::$dbBootstrapped = true;
    }

    private function schemaReady(KernelInterface $kernel): bool
    {
        try {
            /** @var \Doctrine\ORM\EntityManagerInterface $em */
            $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
            $count = $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM sqlite_master WHERE type = :type AND name = :name',
                ['type' => 'table', 'name' => 'users'],
            );
            return (int) $count === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    // ========================================================================
    // 1. Order pay via wallet gateway
    // ========================================================================

    public function testOrderPaidViaWalletGatewayMarksOrderPaidAndMovesFunds(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        [$systemUser] = $this->createUserWithWallet('pt-sys-wallet@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/wallet", [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertSame(Invoice::STATUS_PAID, $order->getPaymentStatus());
        self::assertSame(Invoice::PAYMENT_WALLET, $order->getPaymentMethod());
        self::assertNotNull($order->getPaidAt());
        self::assertNotNull($order->getInvoiceId());
        self::assertNotNull($order->getInvoiceNo());

        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame(Invoice::PAYMENT_WALLET, $invoice->getPayment());
        self::assertSame(self::SPEC_PRICE, $invoice->getAmount());

        self::assertSame(5000 - self::SPEC_PRICE, $this->walletBalance($userWallet));
        self::assertSame(self::SPEC_PRICE, $this->walletBalance($systemWallet));
    }

    // ========================================================================
    // 2. Order pay via mock gateway + notify webhook
    // ========================================================================

    public function testOrderPaidViaMockGatewayAndNotifyWebhook(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", []);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());

        [$response, $body] = $this->postNotify([
            'secret' => 'mock',
            'outTradeNo' => $invoice->getOutTradeNo(),
            'status' => Invoice::STATUS_PAID,
            'amount' => self::SPEC_PRICE,
            'currency' => 'CNY',
            'transactionId' => 'txn-webhook-1',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('SUCCESS', $body);

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertSame(Invoice::STATUS_PAID, $order->getPaymentStatus());
        self::assertSame(Invoice::PAYMENT_MOCK, $order->getPaymentMethod());
        self::assertNotNull($order->getPaidAt());

        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame('txn-webhook-1', $invoice->getTransactionId());
    }

    // ========================================================================
    // 3. Notify verification failures (400/FAIL) do not advance the order
    // ========================================================================

    public function testNotifyVerificationFailuresReturn400AndDoNotAdvanceOrder(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", []);

        $this->em->clear();
        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());

        // Wrong secret → verification exception → "FAIL: ..." 400
        [$response, $body] = $this->postNotify([
            'secret' => 'wrong-secret',
            'outTradeNo' => $invoice->getOutTradeNo(),
            'status' => Invoice::STATUS_PAID,
            'amount' => self::SPEC_PRICE,
            'currency' => 'CNY',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringStartsWith('FAIL: Invalid mock payment secret.', $body);

        // Amount mismatch → generic "FAIL" 400
        [$response, $body] = $this->postNotify([
            'secret' => 'mock',
            'outTradeNo' => $invoice->getOutTradeNo(),
            'status' => Invoice::STATUS_PAID,
            'amount' => 1,
            'currency' => 'CNY',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('FAIL', $body);

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());
        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());
    }

    // ========================================================================
    // 4. Refund flow: invoice refund → trade order refunded + money returned
    // ========================================================================

    public function testRefundFlowRefundsOrderAndReturnsMoney(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        [$systemUser] = $this->createUserWithWallet('pt-refund-sys@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/wallet", [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(3500, $this->walletBalance($userWallet));
        self::assertSame(1500, $this->walletBalance($systemWallet));

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/refund", [
            'reason' => 'integration refund',
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());
        self::assertSame(Invoice::STATUS_REFUNDED, $order->getPaymentStatus());
        self::assertNotNull($order->getRefundedAt());

        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_REFUNDED, $invoice->getStatus());
        self::assertSame(self::SPEC_PRICE, $invoice->getRefundedAmount());
        self::assertNotNull($invoice->getRefundedAt());

        self::assertSame(5000, $this->walletBalance($userWallet));
        self::assertSame(0, $this->walletBalance($systemWallet));
    }

    // ========================================================================
    // 5. Invoice cancellation (pending invoice + cancelled → order stays confirmed)
    // ========================================================================

    public function testInvoiceCancellationForPendingInvoiceKeepsOrderConfirmed(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();

        $orderId = $this->createConfirmedOrder($specId, $user->getId());
        $this->em->clear();
        $order = $this->loadOrder($orderId);

        // Create a pending invoice manually linked to the trade order source
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', [
            'sourceType' => 'trade_order',
            'sourceId' => $order->getUuid(),
            'scene' => Invoice::SCENE_ORDER,
            'amount' => self::SPEC_PRICE,
            'payer' => $user->getId(),
        ]);
        $invoiceId = (int) $content['data']['id'];

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/cancel", ['reason' => 'order cancelled']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());
        self::assertSame(Invoice::STATUS_CANCELLED, $order->getPaymentStatus());

        $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());
    }

    // ========================================================================
    // 6. Wallet balance adjustment provider reduces gateway amount
    // ========================================================================

    public function testWalletDeductionPartialReducesMockGatewayAmount(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        [$systemUser] = $this->createUserWithWallet('pt-deduct-sys@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'walletAmount' => 500,
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());

        // Gateway must have seen the ADJUSTED amount (invoice 1500 - deduction 500)
        self::assertSame(1000, $invoice->getExtraData()['pay']['amount']);
        self::assertSame(500, $this->deductionService()->sumAppliedAmount($invoice));

        self::assertSame(4500, $this->walletBalance($userWallet));
        self::assertSame(500, $this->walletBalance($systemWallet));

        // Confirm via webhook using the gateway amount (not the gross amount)
        [$response] = $this->postNotify([
            'secret' => 'mock',
            'outTradeNo' => $invoice->getOutTradeNo(),
            'status' => Invoice::STATUS_PAID,
            'amount' => 1000,
            'currency' => 'CNY',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
    }

    public function testWalletDeductionFullSkipsGatewayAndMarksPaidImmediately(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        [$systemUser] = $this->createUserWithWallet('pt-deduct-full-sys@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'walletAmount' => self::SPEC_PRICE,
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(0, $content['data']['payload']['gatewayAmount']);

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertSame(Invoice::PAYMENT_WALLET, $order->getPaymentMethod());

        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
        self::assertSame(Invoice::PAYMENT_WALLET, $invoice->getPayment());

        self::assertSame(5000 - self::SPEC_PRICE, $this->walletBalance($userWallet));
        self::assertSame(self::SPEC_PRICE, $this->walletBalance($systemWallet));
    }

    public function testWalletDeductionInsufficientBalanceFailsAndOrderStaysConfirmed(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        [$user, $userWallet] = $this->createUserWithWallet('pt-low-balance@example.com', 100);
        [$systemUser] = $this->createUserWithWallet('pt-low-balance-sys@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'walletAmount' => 200,
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());

        // No money must have moved, and no applied deduction must remain
        self::assertSame(100, $this->walletBalance($userWallet));
        self::assertSame(0, $this->walletBalance($systemWallet));

        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PENDING, $invoice->getStatus());
        self::assertSame(0, $this->deductionService()->sumAppliedAmount($invoice));
    }

    // ========================================================================
    // 7. Bad paths / guards
    // ========================================================================

    public function testBadPathGuards(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();

        // --- pay a non-pending (already paid) invoice → 400 ---
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', [
            'sourceType' => 'manual', 'sourceId' => 'bp-1', 'scene' => Invoice::SCENE_ORDER, 'amount' => 100,
        ]);
        $invoiceId = (int) $content['data']['id'];
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", ['autoPaid' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", ['autoPaid' => true]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // --- refund a non-paid (pending) invoice → 400 ---
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', [
            'sourceType' => 'manual', 'sourceId' => 'bp-2', 'scene' => Invoice::SCENE_ORDER, 'amount' => 100,
        ]);
        $invoiceId = (int) $content['data']['id'];
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/refund", ['amount' => 100, 'reason' => 'nope']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // --- cancel a non-cancellable (paid) invoice → 400 ---
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", ['autoPaid' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/cancel", ['reason' => 'nope']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // --- unknown gateway on invoice payment → 400 ---
        $orderId = $this->createConfirmedOrder($specId, $user->getId());
        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/does-not-exist", []);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // --- mismatch payer: user B cannot pay user A's invoice via app endpoint → 404 ---
        [$payerA] = $this->createUserWithWallet('pt-payer-a@example.com', 1000);
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', [
            'sourceType' => 'manual', 'sourceId' => 'bp-3', 'scene' => Invoice::SCENE_ORDER, 'amount' => 100, 'payer' => $payerA->getId(),
        ]);
        $invoiceId = (int) $content['data']['id'];
        $userB = $this->createUser('pt-payer-b@example.com');
        [$response] = $this->jsonRequestAs($userB, 'POST', "/api/v1/app/invoices/{$invoiceId}/pay/mock", ['autoPaid' => true]);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        // --- notify with unknown outTradeNo → 400 FAIL ---
        [$response, $body] = $this->postNotify([
            'secret' => 'mock', 'outTradeNo' => 'NO-SUCH-OUT-TRADE-NO', 'status' => 'paid', 'amount' => 100, 'currency' => 'CNY',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('FAIL', $body);
    }

    // ========================================================================
    // 8. Idempotency: duplicate notify + duplicate deduction attempt
    // ========================================================================

    public function testDuplicateNotifyAndDeductionAttemptAreIdempotent(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        [$systemUser] = $this->createUserWithWallet('pt-idem-sys@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'walletAmount' => 500,
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        // Second payment attempt on the same (paying) invoice must be rejected without double-deducting
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'walletAmount' => 500,
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $this->em->clear();
        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(500, $this->deductionService()->sumAppliedAmount($invoice));
        self::assertSame(4500, $this->walletBalance($userWallet));
        self::assertSame(500, $this->walletBalance($systemWallet));

        // Duplicate notify (simulated gateway retry) → both return SUCCESS and are idempotent
        $notify = [
            'secret' => 'mock', 'outTradeNo' => $invoice->getOutTradeNo(),
            'status' => 'paid', 'amount' => 1000, 'currency' => 'CNY', 'transactionId' => 'txn-idem',
        ];
        [$response] = $this->postNotify($notify);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        [$response] = $this->postNotify($notify);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());

        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());

        // Single deduction still applied, no money deducted twice
        self::assertSame(500, $this->deductionService()->sumAppliedAmount($invoice));
        self::assertSame(4500, $this->walletBalance($userWallet));
        self::assertSame(500, $this->walletBalance($systemWallet));
    }

    // ========================================================================
    // Bug reproductions / documented findings (assert current behaviour)
    // ========================================================================

    public function testPaymentCannotBeRetriedAfterFailedNotify(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", []);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $invoice = $this->loadInvoiceByOrder($orderId);

        // Gateway reports failure via webhook → invoice + order paymentStatus become "failed"
        [$response] = $this->postNotify([
            'secret' => 'mock', 'outTradeNo' => $invoice->getOutTradeNo(),
            'status' => Invoice::STATUS_FAILED, 'amount' => self::SPEC_PRICE, 'currency' => 'CNY',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Invoice::STATUS_FAILED, $invoice->getStatus());

        // BUG-001 reproduction: invoice in failed status cannot be re-paid via start_pay
        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'autoPaid' => true,
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('cannot apply transition "start_pay"', (string) ($content['message'] ?? ''));

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());
        $invoices = $this->em->getRepository(Invoice::class)->findBy(['sourceType' => 'trade_order', 'sourceId' => $order->getUuid()]);
        self::assertCount(1, $invoices);
    }

    public function testDirectInvoiceRefundOfPaidOrderLeavesOrderPaid(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        [$systemUser] = $this->createUserWithWallet('pt-direct-refund-sys@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/wallet", [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $invoice = $this->loadInvoiceByOrder($orderId);
        self::assertSame(Order::STATUS_PAID, $this->loadOrder($orderId)->getStatus());

        // Refund directly on the invoice, bypassing the order workflow (order is still "paid")
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoice->getId()}/refund", [
            'amount' => self::SPEC_PRICE, 'reason' => 'direct refund', 'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());
        self::assertSame(Invoice::STATUS_REFUNDED, $order->getPaymentStatus());
        self::assertSame(5000, $this->walletBalance($userWallet));
    }

    public function testWalletRefundRequiresSystemWalletIdToBeResupplied(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        [$systemUser] = $this->createUserWithWallet('pt-refund-sysid@example.com', 0, ['ROLE_ADMIN']);
        $systemWallet = $this->findWallet($systemUser);

        $orderId = $this->createConfirmedOrder($specId, $user->getId());
        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/wallet", [
            'systemWalletId' => $systemWallet->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        // No systemWalletId re-supplied → the wallet gateway cannot refund (injected default is 0)
        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/refund", ['reason' => 'r']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('systemWalletId is required for wallet refund', (string) ($content['message'] ?? ''));

        $this->em->clear();
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
    }

    public function testInvoicePaidWithMismatchedAmountDoesNotAdvanceOrder(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();
        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $this->em->clear();
        $order = $this->loadOrder($orderId);

        // Manually created invoice for the SAME trade order with a WRONG amount (999 != 1500)
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', [
            'sourceType' => 'trade_order', 'sourceId' => $order->getUuid(),
            'scene' => Invoice::SCENE_ORDER, 'amount' => 999,
        ]);
        $invoiceId = (int) $content['data']['id'];
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", ['autoPaid' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());

        // FINDING: the listener silently skips on amount/currency mismatch (critical log only) — order not paid
        $order = $this->loadOrder($orderId);
        self::assertSame(Order::STATUS_CONFIRMED, $order->getStatus());
        self::assertNotSame(Invoice::STATUS_PAID, $order->getPaymentStatus());
    }

    // ========================================================================
    // Helpers
    // ========================================================================


    private function createInvoiceForOrder(int $orderId, ?int $amount = null): int
    {
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        $payload = [
            'sourceType' => 'trade_order',
            'sourceId' => $order->getUuid(),
            'scene' => Invoice::SCENE_ORDER,
            'amount' => $amount ?? $order->getTotalAmount(),
            'currency' => $order->getCurrency(),
        ];
        if ($order->getUser()?->getId() !== null) {
            $payload['payer'] = $order->getUser()->getId();
        }
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/invoices', $payload);
        self::assertSame(0, $content['code'], 'createInvoiceForOrder failed: '.json_encode($content));
        $invoiceId = (int) $content['data']['id'];
        // Link invoice to order immediately (mimics OrderService::createPayment)
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
        if ($order instanceof Order && $invoice instanceof Invoice) {
            $order->setInvoiceId($invoice->getUuid());
            $order->setInvoiceNo($invoice->getOutTradeNo());
            $order->setPaymentStatus($invoice->getStatus());
            $this->em->flush();
        }
        return $invoiceId;
    }

    private function createProduct(): int
    {
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/products', ['name' => 'PT Product', 'status' => 'active']);
        self::assertSame(0, $content['code']);
        return (int) $content['data']['id'];
    }

    private function createSpecification(int $productId): int
    {
        [, $content] = $this->jsonRequest('POST', "/api/v1/manage/products/{$productId}/specifications", [
            'name' => 'PT Spec', 'price' => self::SPEC_PRICE, 'status' => 'active',
        ]);
        if ($content['code'] !== 0) {
            self::fail('createSpecification failed: ' . json_encode($content));
        }
        return (int) $content['data']['id'];
    }

    private function createConfirmedOrder(int $specId, ?int $userId = null): int
    {
        $payload = ['items' => [['specificationId' => $specId, 'quantity' => 1]], 'currency' => 'CNY'];
        if ($userId !== null) {
            $payload['user'] = $userId;
        }

        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', $payload);
        self::assertSame(0, $content['code']);
        $orderId = (int) $content['data']['id'];

        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/confirm");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return $orderId;
    }

    private function loadOrder(int $orderId): Order
    {
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        return $order;
    }

    private function loadInvoiceByOrder(int $orderId): Invoice
    {
        $order = $this->loadOrder($orderId);
        self::assertNotNull($order->getInvoiceId());
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $order->getInvoiceId()]);
        self::assertInstanceOf(Invoice::class, $invoice);
        return $invoice;
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

    /** @return array{User, Wallet} */
    private function createUserWithWallet(string $email, int $balance, array $roles = ['ROLE_USER']): array
    {
        $user = $this->createUser($email, $roles);
        $wallet = $this->createWallet($user, $balance);
        return [$user, $wallet];
    }

    private function createWallet(User $user, int $balance): Wallet
    {
        $existing = $this->em->getRepository(Wallet::class)->findOneBy(['user' => $user, 'currency' => 'CNY']);
        if ($existing instanceof Wallet) {
            $this->setWalletBalance($existing, $balance);
            return $existing;
        }

        $wallet = new Wallet($user, 'CNY');
        $this->em->persist($wallet);
        $this->em->flush();
        $this->setWalletBalance($wallet, $balance);
        return $wallet;
    }

    private function setWalletBalance(Wallet $wallet, int $balance): void
    {
        $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = :balance WHERE id = :id', [
            'balance' => $balance,
            'id' => $wallet->getId(),
        ]);
    }

    private function findWallet(User $user): Wallet
    {
        $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['user' => $user, 'currency' => 'CNY']);
        self::assertInstanceOf(Wallet::class, $wallet);
        return $wallet;
    }

    private function walletBalance(Wallet $wallet): int
    {
        $this->em->clear();
        $reloaded = $this->em->getRepository(Wallet::class)->find($wallet->getId());
        self::assertInstanceOf(Wallet::class, $reloaded);
        return $reloaded->getBalance();
    }

    private function deductionService(): PaymentDeductionService
    {
        return $this->client->getContainer()->get(PaymentDeductionService::class);
    }

    /** @return array{Response, string} */
    private function postNotify(array $data): array
    {
        $this->client->request('POST', '/api/payment/notify/mock', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();
        return [$response, (string) $response->getContent()];
    }

    /** @return array{Response, array} */
    private function jsonRequest(string $method, string $uri, array $data = []): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();
        return [$response, json_decode($response->getContent(), true) ?? []];
    }

    /** @return array{Response, array} */
    private function jsonRequestAs(User $user, string $method, string $uri, array $data = []): array
    {
        $tokenManager = $this->client->getContainer()->get(\App\Identity\Security\TokenManager::class);
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenManager->createAccessToken($user),
        ], json_encode($data, JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        return [$response, json_decode($response->getContent(), true) ?? []];
    }
}
