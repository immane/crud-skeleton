<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store;

use App\Authorization\Command\SeedAuthorizationCommand;
use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class StaffCatalogAuthorizationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $ref = new \ReflectionProperty(\Symfony\Bundle\FrameworkBundle\Test\KernelTestCase::class, 'booted');
        $ref->setValue(null, false);
    }

    private function seedAuthorization(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $registry = $container->get(\App\Authorization\Service\AuthorizationResourceRegistry::class);
        $command = new SeedAuthorizationCommand($em, $registry);
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\NullOutput();
        $command->run($input, $output);
    }

    public function testStaffCatalogAuthorizationHappyAndSadPaths(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);

        $adminToken = $this->createAdminAndGetToken($client);
        $admin = $this->findUserByEmail('testadmin@example.com');

        $storeXUuid = $this->createStore($client, $adminToken, 'staff-x', 'Staff Store X');
        $storeYUuid = $this->createStore($client, $adminToken, 'staff-y', 'Staff Store Y');
        self::assertNotNull($storeXUuid, 'store X uuid');
        self::assertNotNull($storeYUuid, 'store Y uuid');
        self::assertNotSame($storeXUuid, $storeYUuid);

        $userNoMembership = $this->createUser('staff_nomem@pilot.test', 'staff_nomem');
        $userMemberNoAssignment = $this->createUser('staff_noassign@pilot.test', 'staff_noassign');
        $userCatalogManager = $this->createUser('staff_catalog@pilot.test', 'staff_catalog');
        $userForSuspend = $this->createUser('staff_suspend@pilot.test', 'staff_suspend');

        $tokenNoMem = $this->loginAndGetToken('staff_nomem@pilot.test', 'P@ssw0rd', $client);
        $tokenMemberNoAssign = $this->loginAndGetToken('staff_noassign@pilot.test', 'P@ssw0rd', $client);
        $tokenCatalog = $this->loginAndGetToken('staff_catalog@pilot.test', 'P@ssw0rd', $client);
        $tokenSuspend = $this->loginAndGetToken('staff_suspend@pilot.test', 'P@ssw0rd', $client);

        // Grant membership to member-no-assignment and catalog manager and suspend user
        $this->grantMembership($client, $adminToken, $storeXUuid, $userMemberNoAssignment->getUuid(), 'manager');
        $this->grantMembership($client, $adminToken, $storeXUuid, $userCatalogManager->getUuid(), 'manager');
        $this->grantMembership($client, $adminToken, $storeXUuid, $userForSuspend->getUuid(), 'manager');
        // Also grant admin membership so admin can still manage if needed (not required for staff routes)
        $this->grantMembership($client, $adminToken, $storeXUuid, $admin->getUuid(), 'owner');
        $this->grantMembership($client, $adminToken, $storeYUuid, $admin->getUuid(), 'owner');

        // Grant catalog manager assignment only to userCatalogManager and userForSuspend
        $catalogAssignmentUuid = $this->grantAssignment($client, $adminToken, $userCatalogManager->getUuid(), 'store_catalog_manager', 'store', $storeXUuid);
        $suspendAssignmentUuid = $this->grantAssignment($client, $adminToken, $userForSuspend->getUuid(), 'store_catalog_manager', 'store', $storeXUuid);
        // grant a second store's assignment not relevant
        $this->grantAssignment($client, $adminToken, $admin->getUuid(), 'store_catalog_manager', 'store', $storeYUuid);

        // ---- Sad: staff without membership denied (403) for product create/list ----
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenNoMem);
        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products', $storeXUuid));
        self::assertResponseStatusCodeSame(403, 'no-membership list should be 403: ' . $client->getResponse()->getContent());
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products', $storeXUuid), ['name' => 'Tea']);
        self::assertResponseStatusCodeSame(403, 'no-membership create should be 403: ' . $client->getResponse()->getContent());
        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products/%s/specifications', $storeXUuid, UUID::v4()));
        self::assertResponseStatusCodeSame(403, 'no-membership spec list should be 403: ' . $client->getResponse()->getContent());

        // ---- Sad: staff with membership but without store_catalog_manager assignment denied ----
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenMemberNoAssign);
        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products', $storeXUuid));
        self::assertResponseStatusCodeSame(403, 'member without assignment list should be 403: ' . $client->getResponse()->getContent());
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products', $storeXUuid), ['name' => 'Tea']);
        self::assertResponseStatusCodeSame(403, 'member without assignment create should be 403: ' . $client->getResponse()->getContent());
        // Even if product existed, spec create would be denied before product check
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products/%s/specifications', $storeXUuid, UUID::v4()), ['name' => 'Spec', 'price' => 1000]);
        self::assertResponseStatusCodeSame(403, 'member without assignment spec create should be 403');

        // ---- Happy: staff with assignment creates/lists products and specifications ----
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenCatalog);
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products', $storeXUuid), [
            'name' => 'Tea Product',
            'description' => 'Demo tea',
            'status' => 'active',
        ]);
        self::assertResponseStatusCodeSame(201, 'catalog manager create product should be 201: ' . $client->getResponse()->getContent());
        $created = $this->decodeJson($client);
        $productUuid = $created['data']['uuid'] ?? $created['data']['id'] ?? null;
        // creation returns object or array; handle both
        if (is_array($created['data'] ?? null) && isset($created['data'][0])) {
            $productUuid = $created['data'][0]['uuid'] ?? $created['data'][0]['id'] ?? null;
        }
        self::assertNotNull($productUuid, 'product uuid after create: ' . json_encode($created, JSON_THROW_ON_ERROR));
        // Normalize to string
        $productUuid = (string) $productUuid;

        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products', $storeXUuid));
        self::assertResponseStatusCodeSame(200);
        $list = $this->decodeJson($client);
        self::assertNotEmpty($list['data'] ?? [], 'list should contain created product');

        $client->request('GET', sprintf('/api/v1/store/%s/products/%s', $storeXUuid, $productUuid));
        self::assertResponseStatusCodeSame(200, 'detail happy should be 200: ' . $client->getResponse()->getContent());

        $client->jsonRequest('PUT', sprintf('/api/v1/store/%s/products/%s', $storeXUuid, $productUuid), ['name' => 'Tea Updated']);
        self::assertResponseStatusCodeSame(200, 'update happy should be 200: ' . $client->getResponse()->getContent());
        $updated = $this->decodeJson($client);
        self::assertSame('Tea Updated', $updated['data']['name'] ?? $updated['data']['title'] ?? 'Tea Updated', 'name updated');

        // Spec happy
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products/%s/specifications', $storeXUuid, $productUuid), [
            'name' => 'Large',
            'price' => 6400,
        ]);
        self::assertResponseStatusCodeSame(201, 'spec create happy 201: ' . $client->getResponse()->getContent());
        $specCreated = $this->decodeJson($client);
        $specUuid = $specCreated['data']['uuid'] ?? $specCreated['data']['id'] ?? null;
        if (is_array($specCreated['data'] ?? null) && isset($specCreated['data'][0])) {
            $specUuid = $specCreated['data'][0]['uuid'] ?? $specCreated['data'][0]['id'] ?? null;
        }
        self::assertNotNull($specUuid, 'spec uuid: ' . json_encode($specCreated, JSON_THROW_ON_ERROR));
        $specUuid = (string) $specUuid;

        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products/%s/specifications', $storeXUuid, $productUuid));
        self::assertResponseStatusCodeSame(200, 'spec list happy 200');
        $specList = $this->decodeJson($client);
        self::assertNotEmpty($specList['data'] ?? [], 'spec list not empty');

        $client->request('GET', sprintf('/api/v1/store/%s/products/%s/specifications/%s', $storeXUuid, $productUuid, $specUuid));
        self::assertResponseStatusCodeSame(200, 'spec detail happy 200: ' . $client->getResponse()->getContent());

        $client->jsonRequest('PUT', sprintf('/api/v1/store/%s/products/%s/specifications/%s', $storeXUuid, $productUuid, $specUuid), [
            'name' => 'Large Updated',
            'price' => 7000,
        ]);
        self::assertResponseStatusCodeSame(200, 'spec update happy 200: ' . $client->getResponse()->getContent());

        // Cross-store sad: product from store X should not be accessible via store Y even with assignment for Y (admin)
        // Catalog user has no assignment for store Y, so should be 403
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenCatalog);
        $client->request('GET', sprintf('/api/v1/store/%s/products/%s', $storeYUuid, $productUuid));
        // Expect 403 because userCatalog has no membership/assignment for store Y
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [403, 404], true), 'cross-store detail should be 403 or 404: ' . $client->getResponse()->getContent());

        // Spec sad when product not in store: create spec under non-existent product uuid -> 404
        $fakeProductUuid = UUID::v4();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenCatalog);
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products/%s/specifications', $storeXUuid, $fakeProductUuid), [
            'name' => 'Should Fail',
            'price' => 1000,
        ]);
        self::assertResponseStatusCodeSame(404, 'spec create with product not in store should be 404: ' . $client->getResponse()->getContent());

        // Spec list sad when product deleted/hidden: if product is deleted, spec list should be empty (id -1 filter)
        // We'll test this after product delete below, but also test with fake product => empty list 200
        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products/%s/specifications', $storeXUuid, $fakeProductUuid));
        // The mixin returns 200 with empty data or 403? Actually storeScopedFilter returns id -1 so list returns empty 200.
        // But our token has permission so it should be 200.
        self::assertResponseStatusCodeSame(200, 'spec list with fake product should be 200 empty: ' . $client->getResponse()->getContent());
        $emptySpecList = $this->decodeJson($client);
        self::assertSame([], $emptySpecList['data'] ?? null, 'empty spec list for fake product');

        // ---- Happy delete spec soft-delete (isDeleted true, 204) ----
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenCatalog);
        $client->request('DELETE', sprintf('/api/v1/store/%s/products/%s/specifications/%s', $storeXUuid, $productUuid, $specUuid));
        self::assertResponseStatusCodeSame(204, 'spec delete soft should be 204: ' . $client->getResponse()->getContent());

        // After delete, detail should be 404 (isDeleted false filter)
        $client->request('GET', sprintf('/api/v1/store/%s/products/%s/specifications/%s', $storeXUuid, $productUuid, $specUuid));
        self::assertResponseStatusCodeSame(404, 'spec detail after delete should be 404');

        // Delete sad when already deleted => 404
        $client->request('DELETE', sprintf('/api/v1/store/%s/products/%s/specifications/%s', $storeXUuid, $productUuid, $specUuid));
        self::assertResponseStatusCodeSame(404, 'spec delete already deleted should be 404');

        // ---- Happy delete product soft-delete (isDeleted true, 204) ----
        $client->request('DELETE', sprintf('/api/v1/store/%s/products/%s', $storeXUuid, $productUuid));
        self::assertResponseStatusCodeSame(204, 'product delete soft should be 204');

        $client->request('GET', sprintf('/api/v1/store/%s/products/%s', $storeXUuid, $productUuid));
        self::assertResponseStatusCodeSame(404, 'product detail after delete should be 404');

        // Delete sad when already deleted
        $client->request('DELETE', sprintf('/api/v1/store/%s/products/%s', $storeXUuid, $productUuid));
        self::assertResponseStatusCodeSame(404, 'product delete already deleted should be 404');

        // ---- Revoke assignment sad path denies ----
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('DELETE', sprintf('/api/v1/manage/assignments/%s', $catalogAssignmentUuid));
        self::assertResponseStatusCodeSame(204, 'revoke assignment should be 204: ' . $client->getResponse()->getContent());

        // Clear cache to ensure revocation takes effect (AuthorizationService uses cache.app)
        $container = $client->getContainer();
        $cache = $container->get('cache.app');
        if (method_exists($cache, 'clear')) {
            $cache->clear();
        }

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenCatalog);
        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products', $storeXUuid));
        self::assertResponseStatusCodeSame(403, 'after revoke, catalog manager should be denied 403: ' . $client->getResponse()->getContent());
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products', $storeXUuid), ['name' => 'After revoke']);
        self::assertResponseStatusCodeSame(403, 'after revoke, create should be 403');

        // ---- Suspend membership sad path denies ----
        // Create a product with the still-active suspend user before suspending
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenSuspend);
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products', $storeXUuid), ['name' => 'SuspendTest Product']);
        self::assertResponseStatusCodeSame(201, 'suspend user create before suspend should be 201: ' . $client->getResponse()->getContent());
        $suspendProduct = $this->decodeJson($client);
        $suspendProductUuid = $suspendProduct['data']['uuid'] ?? $suspendProduct['data']['id'] ?? null;
        if (is_array($suspendProduct['data'] ?? null) && isset($suspendProduct['data'][0])) {
            $suspendProductUuid = $suspendProduct['data'][0]['uuid'] ?? null;
        }
        self::assertNotNull($suspendProductUuid);
        $suspendProductUuid = (string) $suspendProductUuid;

        // Suspend membership via entity
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $storeX = $em->getRepository(Store::class)->findOneBy(['uuid' => $storeXUuid]);
        self::assertInstanceOf(Store::class, $storeX);
        $membership = $em->getRepository(Membership::class)->findOneBy(['store' => $storeX, 'userUuid' => $userForSuspend->getUuid()]);
        self::assertInstanceOf(Membership::class, $membership, 'membership for suspend user exists');
        $membership->suspend();
        $em->flush();
        if (method_exists($cache, 'clear')) {
            $cache->clear();
        }

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokenSuspend);
        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products', $storeXUuid));
        self::assertResponseStatusCodeSame(403, 'after suspend, list should be 403: ' . $client->getResponse()->getContent());
        $client->jsonRequest('POST', sprintf('/api/v1/store/%s/products', $storeXUuid), ['name' => 'Should fail after suspend']);
        self::assertResponseStatusCodeSame(403, 'after suspend, create should be 403');
        // Spec list also denied after suspend
        $client->jsonRequest('GET', sprintf('/api/v1/store/%s/products/%s/specifications', $storeXUuid, $suspendProductUuid));
        self::assertResponseStatusCodeSame(403, 'after suspend, spec list should be 403');

        // Even admin with assignment for store Y should not access store X without membership (but admin has owner membership for X, so would pass)
        // To confirm suspension revokes, we also check assignment still exists but membership blocks
        $em2 = static::getContainer()->get(EntityManagerInterface::class);
        $assign = $em2->getRepository(Assignment::class)->findOneBy(['uuid' => $suspendAssignmentUuid]);
        self::assertInstanceOf(Assignment::class, $assign, 'assignment still exists after suspend');
        self::assertNull($assign->getRevokedAt(), 'assignment not revoked, membership is suspended');
    }

    private function createStore(KernelBrowser $client, string $adminToken, string $code, string $name): ?string
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/stores', [
            'code' => $code,
            'name' => $name,
            'timezone' => 'Asia/Shanghai',
        ]);
        if ($client->getResponse()->getStatusCode() !== 201) {
            $client->request('GET', '/api/v1/manage/stores?limit=100');
            $data = $this->decodeJson($client);
            foreach ($data['data'] ?? [] as $store) {
                if (($store['code'] ?? '') === $code) {
                    return $store['uuid'];
                }
            }

            return null;
        }
        $data = $this->decodeJson($client);

        return $data['data']['uuid'] ?? null;
    }

    private function grantMembership(KernelBrowser $client, string $adminToken, string $storeUuid, string $userUuid, string $role): void
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->jsonRequest('POST', sprintf('/api/v1/manage/stores/%s/members', $storeUuid), [
            'userUuid' => $userUuid,
            'role' => $role,
        ]);
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [200, 201], true), 'grant membership failed: ' . $client->getResponse()->getContent());
    }

    private function grantAssignment(KernelBrowser $client, string $adminToken, string $userUuid, string $roleCode, string $scopeType, string $scopeUuid): string
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $adminToken);
        $client->request('GET', '/api/v1/manage/roles');
        $data = $this->decodeJson($client);
        $roleUuid = null;
        foreach ($data['data'] ?? [] as $role) {
            if (($role['code'] ?? '') === $roleCode) {
                $roleUuid = $role['uuid'];
                break;
            }
        }
        self::assertNotNull($roleUuid, sprintf('Role %s not found', $roleCode));

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $userUuid,
            'roleUuid' => $roleUuid,
            'scopeType' => $scopeType,
            'scopeUuid' => $scopeUuid,
        ]);
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [200, 201], true), 'grant assignment failed: ' . $client->getResponse()->getContent());
        $resp = $this->decodeJson($client);
        // assignment uuid is in data.uuid or data
        $assignUuid = $resp['data']['uuid'] ?? $resp['data']['id'] ?? null;
        if ($assignUuid === null) {
            // fallback fetch via GET assignments
            $client->request('GET', '/api/v1/manage/assignments?limit=100');
            $list = $this->decodeJson($client);
            foreach ($list['data'] ?? [] as $a) {
                if (($a['userUuid'] ?? '') === $userUuid && ($a['scopeUuid'] ?? '') === $scopeUuid && ($a['role']['code'] ?? $a['roleCode'] ?? '') === $roleCode) {
                    return $a['uuid'];
                }
                // alternative shape
                if (($a['userUuid'] ?? '') === $userUuid && ($a['scopeUuid'] ?? '') === $scopeUuid) {
                    return $a['uuid'];
                }
            }
            self::fail('Could not determine assignment uuid after grant');
        }

        return (string) $assignUuid;
    }

    private function createUser(string $email, string $username): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($hasher->hashPassword($user, 'P@ssw0rd'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function findUserByEmail(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }

    private function loginAndGetToken(string $identifier, string $password = 'P@ssw0rd', ?KernelBrowser $client = null): string
    {
        $owned = $client === null;
        $client ??= static::createClient();
        $client->jsonRequest('POST', '/api/auth/login', [
            'identifier' => $identifier,
            'password' => $password,
        ]);
        self::assertResponseStatusCodeSame(200, 'login failed for ' . $identifier . ': ' . $client->getResponse()->getContent());
        $data = $this->decodeJson($client);
        if ($owned) {
            self::ensureKernelShutdown();
        }

        return $data['access_token'];
    }

    private function createAdminAndGetToken(?KernelBrowser $client = null): string
    {
        $owned = $client === null;
        $client ??= static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'testadmin@example.com']);
        if ($admin === null) {
            $admin = new User();
            $admin->setEmail('testadmin@example.com');
            $admin->setUsername('testadmin');
            $admin->setPassword($hasher->hashPassword($admin, 'AdminPass!'));
            $admin->setRoles(['ROLE_ADMIN']);
            $em->persist($admin);
            $em->flush();
        }
        if ($owned) {
            self::ensureKernelShutdown();
        }

        return $this->loginAndGetToken('testadmin@example.com', 'AdminPass!', $owned ? null : $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
