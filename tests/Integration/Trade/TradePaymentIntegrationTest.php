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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TradePaymentIntegrationTest extends IntegrationWebTestCase
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

    public function testOrderPaymentAndRefundThroughInvoiceEvents(): void
    {
        $productId = $this->createProduct();
        $specId = $this->createSpecification($productId);
        $user = $this->currentUser();

        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'user' => $user->getId(),
            'items' => [['specificationId' => $specId, 'quantity' => 2]],
            'currency' => 'CNY',
        ]);
        self::assertSame(0, $content['code']);
        $orderId = (int) $content['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/confirm");

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'autoPaid' => true,
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertSame(Invoice::PAYMENT_MOCK, $order->getPaymentMethod());
        self::assertSame(Invoice::STATUS_PAID, $order->getPaymentStatus());
        self::assertNotNull($order->getInvoiceId());
        self::assertNotNull($order->getInvoiceNo());

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/refund", ['reason' => 'invoice refund']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());
        self::assertSame(Invoice::STATUS_REFUNDED, $order->getPaymentStatus());
    }

    public function testAppUserCanSubmitConfirmAndPayOwnOrder(): void
    {
        $productId = $this->createProduct();
        $specId = $this->createSpecification($productId);

        [, $content] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
            'currency' => 'CNY',
        ]);
        self::assertSame(0, $content['code']);
        $orderId = (int) $content['data']['id'];
        self::assertSame(Order::STATUS_DRAFT, $content['data']['status']);

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/app/orders/{$orderId}/submit");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(Order::STATUS_PENDING, $content['data']['status']);

        [$response, $content] = $this->jsonRequest('POST', "/api/v1/app/orders/{$orderId}/confirm");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);
        self::assertSame(Order::STATUS_CONFIRMED, $content['data']['status']);

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response, $content] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'autoPaid' => true,
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(0, $content['code']);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertSame(Invoice::STATUS_PAID, $order->getPaymentStatus());
    }

    public function testAppOrderTransitionFailures(): void
    {
        $productId = $this->createProduct();
        $specId = $this->createSpecification($productId);

        [$response] = $this->jsonRequest('POST', '/api/v1/app/orders/999999/submit');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        [, $content] = $this->jsonRequest('POST', '/api/v1/app/orders', [
            'items' => [['specificationId' => $specId, 'quantity' => 1]],
            'currency' => 'CNY',
        ]);
        $orderId = (int) $content['data']['id'];

        [$response] = $this->jsonRequestAs($this->createUser('other-order-user@example.com'), 'POST', "/api/v1/app/orders/{$orderId}/submit");
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        [$response] = $this->jsonRequest('POST', "/api/v1/app/orders/{$orderId}/submit");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        [$response] = $this->jsonRequest('POST', "/api/v1/app/orders/{$orderId}/submit");
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testOrderPaymentWithWalletDeductionAndMockRemainder(): void
    {
        $productId = $this->createProduct();
        $specId = $this->createSpecification($productId);
        $user = $this->currentUser();
        $userWallet = $this->createWallet($user, 5000);
        $systemUser = $this->createSystemUser('trade-deduct-system@example.com');
        $systemWallet = $this->createWallet($systemUser, 0);

        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/orders', [
            'user' => $user->getId(),
            'items' => [['specificationId' => $specId, 'quantity' => 2]],
            'currency' => 'CNY',
        ]);
        $orderId = (int) $content['data']['id'];

        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/submit");
        $this->jsonRequest('POST', "/api/v1/manage/orders/{$orderId}/do/confirm");

        $invoiceId = $this->createInvoiceForOrder($orderId);
        [$response] = $this->jsonRequest('POST', "/api/v1/manage/invoices/{$invoiceId}/pay/mock", [
            'walletAmount' => 1000,
            'systemWalletId' => $systemWallet->getId(),
            'autoPaid' => true,
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->find($orderId);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertSame(Invoice::PAYMENT_MOCK, $order->getPaymentMethod());
        self::assertSame(Invoice::STATUS_PAID, $order->getPaymentStatus());

        $userWallet = $this->em->getRepository(Wallet::class)->find($userWallet->getId());
        $systemWallet = $this->em->getRepository(Wallet::class)->find($systemWallet->getId());
        self::assertSame(4000, $userWallet->getBalance());
        self::assertSame(1000, $systemWallet->getBalance());
    }


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
        [, $result] = $this->jsonRequest('POST', '/api/v1/manage/invoices', $payload);
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
        return $invoiceId;
    }

    private function createProduct(): int
    {
        [, $content] = $this->jsonRequest('POST', '/api/v1/manage/products', ['name' => 'Payment Product', 'status' => 'active']);
        return (int) $content['data']['id'];
    }

    private function createSpecification(int $productId): int
    {
        [, $content] = $this->jsonRequest('POST', "/api/v1/manage/products/{$productId}/specifications", [
            'name' => 'Payment Spec',
            'price' => 1500,
            'status' => 'active',
        ]);
        return (int) $content['data']['id'];
    }

    private function jsonRequest(string $method, string $uri, array $data = []): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();
        return [$response, json_decode($response->getContent(), true) ?? []];
    }

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

    private function currentUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }

    private function createSystemUser(string $email): User
    {
        return $this->createUser($email, ['ROLE_ADMIN']);
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
