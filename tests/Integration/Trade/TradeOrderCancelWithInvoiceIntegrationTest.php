<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade;

use App\Identity\Entity\User;
use App\Payment\Entity\Invoice;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Entity\Order;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that order cancel correctly propagates to linked invoice cancellation
 * and wallet deduction release, and that OrderInvoiceListener handles
 * InvoiceCancelledEvent / InvoiceFailedEvent.
 */
final class TradeOrderCancelWithInvoiceIntegrationTest extends IntegrationWebTestCase
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

    public function testManageCancelOrderCancelsLinkedInvoiceAndReleasesDeduction(): void
    {
        $productId = $this->createProduct();
        $specId = $this->createSpecification($productId);
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        $systemUser = $this->createSystemUser('cncl-deduct-sys@example.com');
        $systemWallet = $this->createWallet($systemUser, 0);

        // Create order, submit, confirm
        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        // Start payment with wallet deduction (creates invoice in "paying")
        $this->createInvoiceForOrder($orderId, [
            'payment' => Invoice::PAYMENT_MOCK,
            'walletAmount' => 1000,
            'systemWalletId' => $systemWallet->getId(),
        ]);

        // Verify deduction was applied
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertNotNull($order->getInvoiceId());

        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $order->getInvoiceId()]);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(Invoice::STATUS_PAYING, $invoice->getStatus());

        $userWallet = $this->em->getRepository(Wallet::class)->find($userWallet->getId());
        $systemWallet = $this->em->getRepository(Wallet::class)->find($systemWallet->getId());
        self::assertSame(4000, $userWallet->getBalance());
        self::assertSame(1000, $systemWallet->getBalance());

        // Cancel order via the app endpoint, which cancels the linked invoice (invoice-aware cancel).
        [$response, $content] = $this->jsonPost("/api/v1/app/orders/{$orderId}/cancel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        // Verify order is cancelled
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());

        // Verify linked invoice is cancelled
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $order->getInvoiceId()]);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(Invoice::STATUS_CANCELLED, $invoice->getStatus());
        self::assertSame(Invoice::STATUS_CANCELLED, $order->getPaymentStatus());

        // Verify deduction was released (money returned to user wallet)
        $userWallet = $this->em->getRepository(Wallet::class)->find($userWallet->getId());
        $systemWallet = $this->em->getRepository(Wallet::class)->find($systemWallet->getId());
        self::assertSame(5000, $userWallet->getBalance());
        self::assertSame(0, $systemWallet->getBalance());
    }

    public function testManageCancelOrderWithoutInvoiceStillWorks(): void
    {
        $specId = $this->createSpecification($this->createProduct());

        $orderId = $this->createConfirmedOrder($specId);

        [$response, $content] = $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/cancel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        self::assertNull($order->getInvoiceId());
    }

    public function testInvoiceCancelledEventUpdatesOrderPaymentStatus(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        // Start payment without autoPaid to keep invoice in "paying"
        $this->createInvoiceForOrder($orderId, [
            'payment' => Invoice::PAYMENT_MOCK,
        ]);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        $invoiceId = $order->getInvoiceId();
        self::assertNotNull($invoiceId);
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $invoiceId]);
        self::assertInstanceOf(Invoice::class, $invoice);

        // Cancel invoice directly via manage invoice endpoint
        [$response] = $this->jsonPost("/api/v1/manage/invoices/{$invoice->getId()}/cancel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertSame(Invoice::STATUS_CANCELLED, $order->getPaymentStatus());
    }

    public function testInvoiceMarkedFailedEventUpdatesOrderPaymentStatus(): void
    {
        $specId = $this->createSpecification($this->createProduct());
        $user = $this->currentUser();

        $orderId = $this->createConfirmedOrder($specId, $user->getId());

        $this->createInvoiceForOrder($orderId, [
            'payment' => Invoice::PAYMENT_MOCK,
        ]);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        $invoiceId = $order->getInvoiceId();
        self::assertNotNull($invoiceId);
        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['uuid' => $invoiceId]);
        self::assertInstanceOf(Invoice::class, $invoice);

        $invoiceService = $this->client->getContainer()->get(\App\Payment\Service\InvoiceServiceInterface::class);
        $invoiceService->handleNotifyResult(new \App\Payment\DTO\PaymentNotifyResult(
            payment: Invoice::PAYMENT_MOCK,
            outTradeNo: $invoice->getOutTradeNo(),
            status: Invoice::STATUS_FAILED,
            amount: $invoice->getAmount(),
            currency: $invoice->getCurrency(),
        ));

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertSame(Invoice::STATUS_FAILED, $order->getPaymentStatus());
    }

    // ========================================================================
    // Helpers
    // ========================================================================


    private function createInvoiceForOrder(int $orderId, array $payOptions = []): int
    {
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        $payload = [
            'sourceType' => 'trade_order',
            'sourceId' => $order->getUuid(),
            'scene' => Invoice::SCENE_ORDER,
            'amount' => $order->getTotalAmount(),
            'currency' => $order->getCurrency(),
        ];
        if ($order->getUser()?->getId() !== null) {
            $payload['payer'] = $order->getUser()->getId();
        }
        [, $result] = $this->jsonPost('/api/v1/manage/invoices', $payload);
        self::assertSame(0, $result['code'], 'createInvoiceForOrder failed: '.json_encode($result));
        $invoiceId = (int) $result['data']['id'];
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
        if ($order instanceof Order && $invoice instanceof Invoice) {
            $order->setInvoiceId($invoice->getUuid());
            $order->setInvoiceNo($invoice->getOutTradeNo());
            $order->setPaymentStatus($invoice->getStatus());
            $this->em->flush();
        }
        if ($payOptions !== []) {
            $payment = $payOptions['payment'] ?? Invoice::PAYMENT_MOCK;
            unset($payOptions['payment']);
            $this->jsonPost("/api/v1/manage/invoices/{$invoiceId}/pay/{$payment}", $payOptions);
            // Refresh order payment status after pay
            $this->em->clear();
            $order = $this->em->getRepository(Order::class)->find($orderId);
            $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
            if ($order instanceof Order && $invoice instanceof Invoice) {
                $order->setPaymentStatus($invoice->getStatus());
                $this->em->flush();
            }
        }
        return $invoiceId;
    }

    private function createConfirmedOrder(int $specId, ?int $userId = null): int
    {
        $payload = [
            'items' => [['specificationId' => $specId, 'quantity' => 2]],
            'currency' => 'CNY',
        ];
        if ($userId !== null) {
            $payload['user'] = $userId;
        }

        [, $content] = $this->jsonPost('/api/v1/manage/orders', $payload);
        self::assertSame(0, $content['code']);
        $orderId = (int) $content['data']['id'];

        $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/submit");
        $this->jsonPost("/api/v1/manage/orders/{$orderId}/do/confirm");

        return $orderId;
    }

    private function createProduct(): int
    {
        [, $content] = $this->jsonPost('/api/v1/manage/products', ['name' => 'Cancel Test Product', 'status' => 'active']);
        return (int) $content['data']['id'];
    }

    private function createSpecification(int $productId): int
    {
        [, $content] = $this->jsonPost("/api/v1/manage/products/{$productId}/specifications", [
            'name' => 'Cancel Test Spec',
            'price' => 1500,
            'status' => 'active',
        ]);
        return (int) $content['data']['id'];
    }

    /** @return array{Response, array} */
    private function jsonPost(string $uri, array $data = []): array
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();
        return [$response, json_decode($response->getContent(), true) ?? []];
    }

    private function currentUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }

    private function createSystemUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setUsername(strstr($email, '@', true));
        $user->setPassword('password');
        $user->setRoles(['ROLE_ADMIN']);
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
