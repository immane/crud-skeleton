<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade;

use App\Common\Entity\Setting;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Payment\Entity\Invoice;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Trade\Entity\Order;
use App\Wallet\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ThirdPartyPaymentTest extends IntegrationWebTestCase
{
    private KernelBrowser $client;
    private User $owner;
    private User $payer;
    private Order $order;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $this->owner = (new User())->setUsername('payment-owner')->setEmail('payment-owner@example.test')->setRoles(['ROLE_USER']);
        $this->payer = (new User())->setUsername('payment-payer')->setEmail('payment-payer@example.test')->setRoles(['ROLE_USER']);
        $this->order = (new Order())->setUser($this->owner)->setStatus(Order::STATUS_CONFIRMED)->setTotalAmount(1000)
            ->setCurrency('CNY')->setNotes('Private owner notes')->setMetadata(['private' => 'Private owner metadata']);
        foreach ([$this->owner, $this->payer, $this->order] as $entity) $em->persist($entity);
        $em->flush();
    }

    public function testMissingDisabledAndMalformedSettingDenyThirdPartyWithoutCreatingInvoices(): void
    {
        self::assertSame(404, $this->pay($this->payer)->getStatusCode());
        foreach (['false', '0', '', 'TRUE', 'yes', 'invalid', null] as $value) {
            $this->setting($value);
            self::assertSame(404, $this->pay($this->payer)->getStatusCode());
        }
        $this->setting('true', 'string');
        self::assertSame(404, $this->pay($this->payer)->getStatusCode());
        self::assertSame(0, $this->em()->getRepository(Invoice::class)->count([]));
    }

    public function testOwnerPaymentStillWorksWithSettingMissing(): void
    {
        self::assertSame(200, $this->pay($this->owner)->getStatusCode());
        $invoice = $this->linkedInvoice();
        self::assertSame($this->owner->getUuid(), $invoice->getPayer()->getUuid());
        self::assertSame('Private owner notes', $invoice->getDescription());
    }

    public function testSettingMigrationSeedsOnceAndPreservesOperatorChanges(): void
    {
        require_once dirname(__DIR__, 3).'/migrations/Version20261104090000.php';
        $connection = $this->em()->getConnection();
        $migration = new \DoctrineMigrations\Version20261104090000($connection, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        $setting = $this->em()->getRepository(Setting::class)->findOneBy(['key' => 'payment.allowThirdPartyPayment']);
        self::assertSame('false', $setting->getValue()); self::assertSame('boolean', $setting->getType());
        $setting->setValue('true'); $this->em()->flush();
        foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        self::assertSame(1, $this->em()->getRepository(Setting::class)->count(['key' => 'payment.allowThirdPartyPayment']));
        $this->em()->clear();
        self::assertSame('true', $this->em()->getRepository(Setting::class)->findOneBy(['key' => 'payment.allowThirdPartyPayment'])->getValue());
    }

    public function testEnabledThirdPartyPaymentUsesAuthenticatedPayerWithoutChangingOrderOwner(): void
    {
        $this->setting('true');
        $response = $this->pay($this->payer, ['payment' => 'mock', 'autoPaid' => true,
            '_payer' => ['id' => $this->owner->getId()], 'payer' => $this->owner->getId()]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $invoice = $this->linkedInvoice();
        self::assertSame($this->payer->getUuid(), $invoice->getPayer()->getUuid());
        self::assertNull($invoice->getDescription());
        $order = $this->em()->getRepository(Order::class)->find($this->order->getId());
        self::assertSame($this->owner->getUuid(), $order->getUser()->getUuid());
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
        self::assertStringNotContainsString('Private owner', $response->getContent());
        self::assertStringNotContainsString($this->owner->getEmail(), $response->getContent());
        self::assertSame(400, $this->pay($this->payer)->getStatusCode());
        self::assertSame(1, $this->em()->getRepository(Invoice::class)->count([]));
    }

    public function testAnonymousAndOtherOperationsRemainRestricted(): void
    {
        $this->setting('true');
        self::assertSame(401, $this->pay(null)->getStatusCode());
        $uuid = $this->order->getUuid();
        foreach (['' => 'GET', '/items' => 'GET', '/submit' => 'POST', '/confirm' => 'POST', '/cancel' => 'POST', '/refund' => 'POST'] as $suffix => $method) {
            self::assertSame(404, $this->request($this->payer, $method, '/api/v1/app/orders/'.$uuid.$suffix)->getStatusCode());
        }
        $response = $this->request($this->payer, 'GET', '/api/v1/app/orders');
        self::assertSame([], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']);
        self::assertSame(0, $this->em()->getRepository(Invoice::class)->count([]));
    }

    public function testEnabledPolicyAllowsPaymentForAnOrderWithoutAnOwner(): void
    {
        $this->setting('true');
        $this->order->setUser(null); $this->em()->flush();
        self::assertSame(200, $this->pay($this->payer)->getStatusCode());
        self::assertSame($this->payer->getUuid(), $this->linkedInvoice()->getPayer()->getUuid());
        self::assertNull($this->em()->getRepository(Order::class)->find($this->order->getId())->getUser());
    }

    public function testPendingInvoiceOfAnotherPayerIsNotReassignedOrCharged(): void
    {
        $this->setting('true');
        $old = $this->pendingInvoice($this->owner);
        self::assertSame(200, $this->pay($this->payer)->getStatusCode());
        $new = $this->linkedInvoice();
        self::assertNotSame($old->getUuid(), $new->getUuid());
        self::assertSame($this->payer->getUuid(), $new->getPayer()->getUuid());
        $old = $this->em()->getRepository(Invoice::class)->find($old->getId());
        self::assertSame($this->owner->getUuid(), $old->getPayer()->getUuid());
        self::assertSame(Invoice::STATUS_PENDING, $old->getStatus());
    }

    public function testPendingInvoiceForSamePayerIsReusedWithNumericOrderId(): void
    {
        $this->setting('true');
        $pending = $this->pendingInvoice($this->payer);
        self::assertSame(200, $this->pay($this->payer, id: (string) $this->order->getId())->getStatusCode());
        self::assertSame($pending->getUuid(), $this->linkedInvoice()->getUuid());
        self::assertSame(1, $this->em()->getRepository(Invoice::class)->count([]));
        self::assertSame(404, $this->request($this->owner, 'POST', '/api/v1/app/invoices/'.$pending->getId().'/pay/mock')->getStatusCode());
        self::assertSame(404, $this->request($this->owner, 'GET', '/api/v1/app/invoices/'.$pending->getId())->getStatusCode());
        self::assertSame(200, $this->request($this->payer, 'GET', '/api/v1/app/invoices/'.$pending->getId())->getStatusCode());
    }

    public function testWalletPaymentDebitsOnlyActualPayer(): void
    {
        $this->setting('true');
        $em = $this->em();
        $owner = $em->getRepository(User::class)->find($this->owner->getId());
        $payer = $em->getRepository(User::class)->find($this->payer->getId());
        $system = (new User())->setUsername('payment-system')->setEmail('payment-system@example.test');
        $em->persist($system);
        $wallets = [new Wallet($owner, 'CNY'), new Wallet($payer, 'CNY'), new Wallet($system, 'CNY')];
        foreach ($wallets as $wallet) $em->persist($wallet);
        $em->flush();
        foreach (array_slice($wallets, 0, 2) as $wallet) {
            $em->getConnection()->executeStatement('UPDATE wallet SET balance = ? WHERE id = ?', [5000, $wallet->getId()]);
            $em->refresh($wallet);
        }
        $response = $this->pay($this->payer, ['payment' => 'wallet', 'systemWalletId' => $wallets[2]->getId()]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame(5000, (int) $this->em()->getConnection()->fetchOne('SELECT balance FROM wallet WHERE id = ?', [$wallets[0]->getId()]));
        self::assertSame(4000, (int) $this->em()->getConnection()->fetchOne('SELECT balance FROM wallet WHERE id = ?', [$wallets[1]->getId()]));
        self::assertSame($this->payer->getUuid(), $this->linkedInvoice()->getPayer()->getUuid());
    }

    public function testPayingInvoiceIsPreservedWhenCreatingAnotherPayment(): void
    {
        $this->setting('true');
        $old = $this->pendingInvoice($this->owner);
        $old->setStatus(Invoice::STATUS_PAYING); $this->em()->flush();
        self::assertSame(200, $this->pay($this->payer)->getStatusCode());
        self::assertNotSame($old->getUuid(), $this->linkedInvoice()->getUuid());
        $old = $this->em()->getRepository(Invoice::class)->find($old->getId());
        self::assertSame(Invoice::STATUS_PAYING, $old->getStatus());
        self::assertSame($this->owner->getUuid(), $old->getPayer()->getUuid());
    }

    private function em(): EntityManagerInterface
    {
        return $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    private function setting(?string $value, string $type = 'boolean'): void
    {
        $em = $this->em();
        $setting = $em->getRepository(Setting::class)->findOneBy(['key' => 'payment.allowThirdPartyPayment']) ?? new Setting('payment.allowThirdPartyPayment');
        $setting->setValue($value)->setType($type);
        $em->persist($setting); $em->flush();
    }

    private function pendingInvoice(User $payer): Invoice
    {
        $em = $this->em();
        $payer = $em->getRepository(User::class)->find($payer->getId());
        $invoice = (new Invoice())->setPayer($payer)->setAmount(1000)->setCurrency('CNY')
            ->setSourceType('trade_order')->setSourceId($this->order->getUuid())->setScene('order');
        $em->persist($invoice);
        $order = $em->getRepository(Order::class)->find($this->order->getId());
        $order->setInvoiceId($invoice->getUuid()); $em->flush();
        return $invoice;
    }

    private function linkedInvoice(): Invoice
    {
        $em = $this->em(); $em->clear();
        $order = $em->getRepository(Order::class)->find($this->order->getId());
        return $em->getRepository(Invoice::class)->findOneBy(['uuid' => $order->getInvoiceId()]);
    }

    private function pay(?User $payer, array $data = ['payment' => 'mock'], ?string $id = null): Response
    {
        return $this->request($payer, 'POST', '/api/v1/app/orders/'.($id ?? $this->order->getUuid()).'/payment', $data);
    }

    private function request(?User $payer, string $method, string $path, array $data = []): Response
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($payer !== null) $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$this->client->getContainer()->get(TokenManager::class)->createAccessToken($payer);
        $this->client->request($method, $path, [], [], $headers, json_encode($data, JSON_THROW_ON_ERROR));
        return $this->client->getResponse();
    }
}
