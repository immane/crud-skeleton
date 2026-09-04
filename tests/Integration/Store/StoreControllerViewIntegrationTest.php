<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store;

use App\Identity\Entity\User;
use App\Store\Entity\Store;
use App\Store\Entity\StoreOrder;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class StoreControllerViewIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    public function testStoreViewsManageViewsAndStaffViews(): void
    {
        $client = self::createAuthenticatedClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $client->request('POST', '/api/v1/manage/stores', [], [], [], json_encode([
            'code' => 'xuhui',
            'name' => 'Xuhui Store',
            'timezone' => 'Asia/Shanghai',
            'settings' => ['acceptingOrders' => true],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $storeUuid = $created['data']['uuid'];

        $client->request('POST', '/api/v1/manage/stores', [], [], [], json_encode([
            'code' => 'invalid',
            'name' => 'Invalid Store',
            'timezone' => 'UTC',
            'settings' => 'not-an-object',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/v1/manage/stores/' . $storeUuid);
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/manage/stores/00000000-0000-4000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/v1/manage/stores');
        self::assertResponseIsSuccessful();

        $client->request('PUT', '/api/v1/manage/stores/' . $storeUuid, [], [], [], json_encode([
            'name' => 'Updated Xuhui Store',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $client->request('PUT', '/api/v1/manage/stores/' . $storeUuid, [], [], [], json_encode([
            'timezone' => 'Invalid/Timezone',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/v1/app/stores');
        self::assertResponseIsSuccessful();
        $appStores = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $appStores['data']);

        $store = $entityManager->getRepository(Store::class)->findOneBy(['uuid' => $storeUuid]);
        self::assertInstanceOf(Store::class, $store);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertInstanceOf(User::class, $user);

        $storeOrder = new StoreOrder(
            $store,
            'd17f7d36-48b8-4c5c-99c7-a282dbd71784',
            $store->getCode(),
            $store->getName(),
            $user->getUuid(),
            'CNY',
            12800,
            ['items' => [], 'delivery' => [], 'placedAt' => '2026-07-25T00:00:00+00:00'],
        );
        $storeOrder->accept();
        $entityManager->persist($storeOrder);
        $entityManager->flush();

        $client->request('GET', '/api/v1/app/store-orders');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/app/store-orders/' . $storeOrder->getUuid());
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/manage/store-orders');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/manage/store-orders/' . $storeOrder->getUuid());
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/manage/store-orders/00000000-0000-4000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/api/v1/manage/stores/' . $storeUuid . '/members', [], [], [], json_encode([
            'userUuid' => $user->getUuid(),
            'role' => 'manager',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $client->request('POST', '/api/v1/manage/stores/' . $storeUuid . '/members', [], [], [], '{}');
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/api/v1/manage/stores/' . $storeUuid . '/members', [], [], [], json_encode([
            'userUuid' => $user->getUuid(),
            'role' => 'invalid',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/api/v1/manage/stores/' . $storeUuid . '/members');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/store/' . $storeUuid . '/orders');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/store/' . $storeUuid . '/orders/' . $storeOrder->getUuid());
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/v1/store/' . $storeUuid . '/orders/00000000-0000-4000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/api/v1/store/' . $storeUuid . '/orders/' . $storeOrder->getUuid() . '/fulfill', [], [], [], json_encode([
            'fulfillmentData' => ['mode' => 'pickup'],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/v1/manage/stores/' . $storeUuid . '/status/suspend');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/v1/manage/stores/00000000-0000-4000-8000-000000000000/status/suspend');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/v1/app/stores');
        $suspendedStores = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(0, $suspendedStores['data']);
        $client->request('GET', '/api/v1/app/stores/' . $storeUuid);
        self::assertResponseStatusCodeSame(404);
    }
}
