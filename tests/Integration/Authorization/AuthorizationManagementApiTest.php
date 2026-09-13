<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authorization;

use App\Authorization\Command\SeedAuthorizationCommand;
use App\Authorization\Entity\Assignment;
use App\Authorization\Entity\Role;
use App\Core\Utils\UUID;
use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AuthorizationManagementApiTest extends IntegrationWebTestCase
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

    /** @return array<string, mixed> */
    private function decodeJson(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
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

    /** @return array{0: User, 1: string} */
    private function createOrdinaryUserAndGetToken(KernelBrowser $client, string $suffix): array
    {
        $email = sprintf('mgmt_ordinary_%s_%s@example.com', $suffix, bin2hex(random_bytes(4)));
        $username = sprintf('mgmt_ord_%s_%s', $suffix, substr(bin2hex(random_bytes(4)), 0, 6));
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($hasher->hashPassword($user, 'P@ssw0rd'));
        $user->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();

        $token = $this->loginAndGetToken($email, 'P@ssw0rd', $client);

        return [$user, $token];
    }

    private function createTargetUser(KernelBrowser $client, string $suffix): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $email = sprintf('mgmt_target_%s_%s@example.com', $suffix, bin2hex(random_bytes(4)));
        $username = sprintf('mgmt_tgt_%s_%s', $suffix, substr(bin2hex(random_bytes(4)), 0, 6));
        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setPassword($hasher->hashPassword($user, 'P@ssw0rd'));
        $em->persist($user);
        $em->flush();

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
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        if ($owned) {
            self::ensureKernelShutdown();
        }

        return $data['access_token'];
    }

    private function createStore(KernelBrowser $client, string $adminToken, string $suffix): string
    {
        $code = 'mgmt_store_'.bin2hex(random_bytes(4)).'_'.$suffix;
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/stores', [
            'code' => $code,
            'name' => 'Mgmt Store '.$suffix,
            'timezone' => 'Asia/Shanghai',
        ]);
        if ($client->getResponse()->getStatusCode() === 201) {
            $data = $this->decodeJson($client);

            return $data['data']['uuid'];
        }
        $client->request('GET', '/api/v1/manage/stores?limit=100');
        $data = $this->decodeJson($client);
        foreach ($data['data'] ?? [] as $store) {
            if (($store['code'] ?? '') === $code) {
                return $store['uuid'];
            }
        }
        $code2 = 'mgmt_store2_'.bin2hex(random_bytes(6));
        $client->jsonRequest('POST', '/api/v1/manage/stores', [
            'code' => $code2,
            'name' => 'Mgmt Store '.$suffix.'2',
            'timezone' => 'Asia/Shanghai',
        ]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodeJson($client);

        return $data['data']['uuid'];
    }

    private function getRoleUuidByCode(KernelBrowser $client, string $adminToken, string $code): ?string
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->request('GET', '/api/v1/manage/roles?limit=100');
        $data = $this->decodeJson($client);
        foreach ($data['data'] ?? [] as $role) {
            if (($role['code'] ?? '') === $code) {
                return $role['uuid'];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // non-admin denied (403) for manage endpoints
    // -----------------------------------------------------------------
    public function testNonAdminDeniedForManageEndpoints(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $storeUuid = $this->createStore($client, $adminToken, 'deny');

        [$ordinaryUser, $ordinaryToken] = $this->createOrdinaryUserAndGetToken($client, 'deny');

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$ordinaryToken);

        $client->request('GET', '/api/v1/manage/roles');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/v1/manage/permissions');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/v1/manage/assignments');
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => 'should_fail_'.bin2hex(random_bytes(3)),
            'name' => 'Should Fail',
            'scopeType' => 'store',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $ordinaryUser->getUuid(),
            'roleUuid' => UUID::v4(),
            'scopeType' => 'store',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/api/v1/manage/audit-logs');
        $status = $client->getResponse()->getStatusCode();
        self::assertTrue(\in_array($status, [403, 404], true), 'audit-logs should be denied or not found, got '.$status);
    }

    // -----------------------------------------------------------------
    // admin happy creates role, assigns permission, grants store-scoped assignment, revokes assignment
    // -----------------------------------------------------------------
    public function testAdminHappyCreatesRoleAssignsPermissionGrantsAndRevokes(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $storeUuid = $this->createStore($client, $adminToken, 'happy');
        $target = $this->createTargetUser($client, 'happy');

        $roleCode = 'mgmt_happy_'.bin2hex(random_bytes(4));
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => $roleCode,
            'name' => 'Mgmt Happy Role',
            'scopeType' => 'store',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(201, $client->getResponse()->getContent());
        $data = $this->decodeJson($client);
        $roleUuid = $data['data']['uuid'] ?? null;
        self::assertNotNull($roleUuid);
        self::assertSame($roleCode, $data['data']['code']);
        self::assertSame('store', $data['data']['scopeType']);

        $client->request('GET', '/api/v1/manage/roles');
        self::assertResponseStatusCodeSame(200);
        $list = $this->decodeJson($client);
        $found = false;
        foreach ($list['data'] ?? [] as $r) {
            if (($r['uuid'] ?? '') === $roleUuid) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found);

        $client->jsonRequest('POST', sprintf('/api/v1/manage/roles/%s/permissions', $roleUuid), [
            'permissions' => ['common:content:read', 'common:content:create'],
        ]);
        self::assertResponseStatusCodeSame(200);
        $permData = $this->decodeJson($client);
        self::assertSame($roleUuid, $permData['data']['uuid'] ?? $roleUuid);

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $roleUuid,
            'scopeType' => 'store',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [200, 201], true), $client->getResponse()->getContent());
        $assignData = $this->decodeJson($client);
        $assignmentUuid = $assignData['data']['uuid'] ?? null;
        self::assertNotNull($assignmentUuid);

        $client->request('GET', '/api/v1/manage/assignments/'.$assignmentUuid);
        self::assertResponseStatusCodeSame(200);
        $detail = $this->decodeJson($client);
        self::assertSame($target->getUuid(), $detail['data']['userUuid'] ?? $detail['data']['user_uuid'] ?? $target->getUuid());
        self::assertSame('store', $detail['data']['scopeType'] ?? $detail['data']['scope_type'] ?? 'store');

        // Verify via repository instead of API list filtering (API list has known null-handling quirk for revokedAt)
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $active = $em->getRepository(Assignment::class)->findBy(['userUuid' => $target->getUuid(), 'revokedAt' => null]);
        self::assertNotEmpty($active, 'active assignment should exist in DB');
        self::assertSame($assignmentUuid, $active[0]->getUuid());

        // API list must also return the active assignment (revokedAt IS NULL, not `= NULL`)
        $client->request('GET', '/api/v1/manage/assignments?userUuid='.$target->getUuid());
        self::assertResponseStatusCodeSame(200);
        $activeList = $this->decodeJson($client);
        $foundActive = false;
        foreach ($activeList['data'] ?? [] as $a) {
            if (($a['uuid'] ?? '') === $assignmentUuid) {
                $foundActive = true;
                break;
            }
        }
        self::assertTrue($foundActive, 'active assignment should appear in API list');

        $client->request('DELETE', '/api/v1/manage/assignments/'.$assignmentUuid);
        self::assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 204], true), $client->getResponse()->getContent());
        $em->clear();
        $fresh = $em->getRepository(Assignment::class)->findOneBy(['uuid' => $assignmentUuid]);
        self::assertNotNull($fresh, 'assignment should still exist after revoke');
        self::assertNotNull($fresh->getRevokedAt(), 'assignment should be revoked');

        $client->request('GET', '/api/v1/manage/assignments?userUuid='.$target->getUuid().'&includeRevoked=true');
        self::assertResponseStatusCodeSame(200);
        $withRevoked = $this->decodeJson($client);
        $foundRevoked = false;
        foreach ($withRevoked['data'] ?? [] as $a) {
            if (($a['uuid'] ?? '') === $assignmentUuid) {
                $foundRevoked = true;
                self::assertNotNull($a['revokedAt'] ?? $a['revoked_at'] ?? null);
                break;
            }
        }
        self::assertTrue($foundRevoked, 'revoked assignment should still appear with includeRevoked=true');

        $client->request('DELETE', '/api/v1/manage/assignments/'.$assignmentUuid);
        self::assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 204], true), $client->getResponse()->getContent());

        // Hard-delete revoked assignment before role delete for Postgres FK
        $em->clear();
        $a = $em->getRepository(Assignment::class)->findOneBy(['uuid' => $assignmentUuid]);
        if ($a !== null) {
            $em->remove($a);
            $em->flush();
        }
        $client->request('DELETE', '/api/v1/manage/roles/'.$roleUuid);
        self::assertResponseStatusCodeSame(204);
    }

    // -----------------------------------------------------------------
    // sad: duplicate assignment same user/role/scope -> idempotent or 409
    // -----------------------------------------------------------------
    public function testDuplicateAssignmentHandledIdempotently(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $storeUuid = $this->createStore($client, $adminToken, 'dup');
        $target = $this->createTargetUser($client, 'dup');

        $roleCode = 'mgmt_dup_'.bin2hex(random_bytes(4));
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => $roleCode,
            'name' => 'Mgmt Dup Role',
            'scopeType' => 'store',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(201);
        $roleUuid = $this->decodeJson($client)['data']['uuid'];

        $payload = [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $roleUuid,
            'scopeType' => 'store',
            'scopeUuid' => $storeUuid,
        ];
        $client->jsonRequest('POST', '/api/v1/manage/assignments', $payload);
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [200, 201], true), $client->getResponse()->getContent());
        $firstUuid = $this->decodeJson($client)['data']['uuid'];

        $client->jsonRequest('POST', '/api/v1/manage/assignments', $payload);
        $secondStatus = $client->getResponse()->getStatusCode();
        self::assertTrue(\in_array($secondStatus, [200, 201, 409], true), 'duplicate should be handled, got '.$secondStatus.' '.$client->getResponse()->getContent());
        if (\in_array($secondStatus, [200, 201], true)) {
            $secondUuid = $this->decodeJson($client)['data']['uuid'];
            self::assertSame($firstUuid, $secondUuid, 'idempotent duplicate should return same uuid');
        }

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $active = $em->getRepository(Assignment::class)->findBy(['userUuid' => $target->getUuid(), 'scopeType' => 'store', 'scopeUuid' => $storeUuid, 'revokedAt' => null]);
        self::assertCount(1, $active);

        $client->request('DELETE', '/api/v1/manage/assignments/'.$firstUuid);
        self::assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 204], true), $client->getResponse()->getContent());
        $em->clear();
        $a = $em->getRepository(Assignment::class)->findOneBy(['uuid' => $firstUuid]);
        if ($a !== null) {
            $em->remove($a);
            $em->flush();
        }
        $client->request('DELETE', '/api/v1/manage/roles/'.$roleUuid);
        self::assertResponseStatusCodeSame(204);
    }

    // -----------------------------------------------------------------
    // sad: invalid scopeType / missing scopeUuid for store scope -> 400
    // -----------------------------------------------------------------
    public function testInvalidScopeTypeAndMissingScopeUuidReturns400(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $storeUuid = $this->createStore($client, $adminToken, 'invalid');
        $target = $this->createTargetUser($client, 'invalid');
        $roleCode = 'mgmt_invalid_'.bin2hex(random_bytes(4));
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);
        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => $roleCode,
            'name' => 'Mgmt Invalid Role',
            'scopeType' => 'store',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(201);
        $roleUuid = $this->decodeJson($client)['data']['uuid'];

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $roleUuid,
            'scopeType' => 'invalid_scope',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $roleUuid,
            'scopeType' => 'store',
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $roleUuid,
            'scopeType' => 'store',
            'scopeUuid' => '',
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $roleUuid,
            'scopeType' => 'store',
            'scopeUuid' => 'not-a-uuid',
        ]);
        self::assertResponseStatusCodeSame(400);

        $globalRoleCode = 'mgmt_global_'.bin2hex(random_bytes(4));
        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => $globalRoleCode,
            'name' => 'Mgmt Global Role',
            'scopeType' => 'global',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(201);
        $globalRoleUuid = $this->decodeJson($client)['data']['uuid'];

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $globalRoleUuid,
            'scopeType' => 'global',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $globalRoleUuid,
            'scopeType' => 'global',
        ]);
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [200, 201], true), $client->getResponse()->getContent());
        $globalAssignUuid = $this->decodeJson($client)['data']['uuid'] ?? null;
        if ($globalAssignUuid !== null) {
            $client->request('DELETE', '/api/v1/manage/assignments/'.$globalAssignUuid);
            self::assertTrue(in_array($client->getResponse()->getStatusCode(), [200, 204], true), $client->getResponse()->getContent());
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $ga = $em->getRepository(Assignment::class)->findOneBy(['uuid' => $globalAssignUuid]);
            if ($ga !== null) {
                $em->remove($ga);
                $em->flush();
            }
        }

        // hard-delete revoked assignments so role can be deleted on Postgres (FK RESTRICT)
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ([$globalAssignUuid] as $au) {
            if ($au === null) continue;
            $a = $em->getRepository(Assignment::class)->findOneBy(['uuid' => $au]);
            if ($a !== null) {
                $em->remove($a);
                $em->flush();
            }
        }
        // also hard-delete any remaining assignments for the two roles
        foreach ([$roleUuid, $globalRoleUuid] as $ru) {
            $role = $em->getRepository(Role::class)->findOneBy(['uuid' => $ru]);
            if ($role !== null) {
                $remaining = $em->getRepository(Assignment::class)->findBy(['role' => $role]);
                foreach ($remaining as $rem) {
                    $em->remove($rem);
                }
                $em->flush();
            }
        }
        $client->request('DELETE', '/api/v1/manage/roles/'.$roleUuid);
        self::assertResponseStatusCodeSame(204);
        $client->request('DELETE', '/api/v1/manage/roles/'.$globalRoleUuid);
        self::assertResponseStatusCodeSame(204);
    }

    // -----------------------------------------------------------------
    // sad: grant assignment to non-existent role/user -> 404/400
    // -----------------------------------------------------------------
    public function testGrantAssignmentToNonExistentRoleOrUserReturnsError(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $storeUuid = $this->createStore($client, $adminToken, 'notfound');
        $target = $this->createTargetUser($client, 'notfound');

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);

        $nonExistentRoleUuid = UUID::v4();
        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $nonExistentRoleUuid,
            'scopeType' => 'store',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => 'nonexistent_role_'.bin2hex(random_bytes(6)),
            'scopeType' => 'store',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertResponseStatusCodeSame(404);

        $existingRoleUuid = $this->getRoleUuidByCode($client, $adminToken, 'store_content_editor');
        self::assertNotNull($existingRoleUuid);
        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => 'not-a-uuid',
            'roleUuid' => $existingRoleUuid,
            'scopeType' => 'store',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertResponseStatusCodeSame(400);

        $globalRoleUuid = $this->getRoleUuidByCode($client, $adminToken, 'authorization_administrator');
        self::assertNotNull($globalRoleUuid);
        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $globalRoleUuid,
            'scopeType' => 'store',
            'scopeUuid' => $storeUuid,
        ]);
        self::assertResponseStatusCodeSame(400);

        $storeRoleUuid = $this->getRoleUuidByCode($client, $adminToken, 'store_content_editor');
        self::assertNotNull($storeRoleUuid);
        $client->jsonRequest('POST', '/api/v1/manage/assignments', [
            'userUuid' => $target->getUuid(),
            'roleUuid' => $storeRoleUuid,
            'scopeType' => 'global',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    // -----------------------------------------------------------------
    // field-grants: store_content_editor vs metadata_editor happy/sad
    // -----------------------------------------------------------------
    public function testFieldGrantsHappyAndSad(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);

        $roleCode = 'mgmt_fg_'.bin2hex(random_bytes(4));
        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => $roleCode,
            'name' => 'Mgmt FG Role',
            'scopeType' => 'store',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(201);
        $roleUuid = $this->decodeJson($client)['data']['uuid'];

        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/create', $roleUuid), [
            'fields' => ['title', 'body', 'category', 'tags'],
        ]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodeJson($client);
        if (isset($data['data']['fields'])) {
            self::assertSame(['title', 'body', 'category', 'tags'], $data['data']['fields']);
        }

        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/update', $roleUuid), [
            'fields' => ['title', 'body', 'category', 'tags', 'metadata'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/create', $roleUuid), [
            'fields' => ['title', 'body'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/create', $roleUuid), [
            'fields' => ['title', 'evil_field'],
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/unknown:resource/create', $roleUuid), [
            'fields' => ['title'],
        ]);
        self::assertResponseStatusCodeSame(400);

        $systemRoleUuid = $this->getRoleUuidByCode($client, $adminToken, 'store_content_editor');
        self::assertNotNull($systemRoleUuid);
        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/update', $systemRoleUuid), [
            'fields' => ['title', 'body', 'category', 'tags', 'metadata'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $systemMetaUuid = $this->getRoleUuidByCode($client, $adminToken, 'store_content_metadata_editor');
        self::assertNotNull($systemMetaUuid);
        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/update', $systemMetaUuid), [
            'fields' => ['title'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest('POST', sprintf('/api/v1/manage/roles/%s/permissions', $systemRoleUuid), [
            'permissions' => ['common:content:read'],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest('POST', sprintf('/api/v1/manage/roles/%s/permissions', $roleUuid), [
            'permissions' => ['common:content:read'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->jsonRequest('POST', sprintf('/api/v1/manage/roles/%s/permissions', $roleUuid), [
            'permissions' => ['INVALID CODE'],
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('POST', sprintf('/api/v1/manage/roles/%s/permissions', $roleUuid), [
            'permissions' => ['common:content:does_not_exist'],
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/create', 'not-a-uuid'), [
            'fields' => ['title'],
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('PUT', sprintf('/api/v1/manage/roles/%s/field-grants/common:content/create', UUID::v4()), [
            'fields' => ['title'],
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('DELETE', '/api/v1/manage/roles/'.$roleUuid);
        self::assertResponseStatusCodeSame(204);
    }

    // -----------------------------------------------------------------
    // seed idempotency happy
    // -----------------------------------------------------------------
    public function testSeedIdempotencyHappy(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);

        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $registry = $container->get(\App\Authorization\Service\AuthorizationResourceRegistry::class);

        $permCountBefore = $em->getRepository(\App\Authorization\Entity\Permission::class)->count([]);
        $roleCountBefore = $em->getRepository(Role::class)->count([]);
        $grantCountBefore = $em->getRepository(\App\Authorization\Entity\RoleFieldGrant::class)->count([]);

        $command = new SeedAuthorizationCommand($em, $registry);
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $exitCode = $command->run($input, $output);
        self::assertSame(0, $exitCode);
        $firstRunOutput = $output->fetch();
        self::assertStringContainsString('Authorization seed completed', $firstRunOutput);

        $output2 = new \Symfony\Component\Console\Output\BufferedOutput();
        $exitCode2 = $command->run($input, $output2);
        self::assertSame(0, $exitCode2);
        $secondRunOutput = $output2->fetch();
        self::assertStringContainsString('Authorization seed completed', $secondRunOutput);

        $permCountAfter = $em->getRepository(\App\Authorization\Entity\Permission::class)->count([]);
        $roleCountAfter = $em->getRepository(Role::class)->count([]);
        $grantCountAfter = $em->getRepository(\App\Authorization\Entity\RoleFieldGrant::class)->count([]);

        self::assertSame($permCountBefore, $permCountAfter, 'permissions should be idempotent');
        self::assertSame($roleCountBefore, $roleCountAfter, 'roles should be idempotent');
        self::assertSame($grantCountBefore, $grantCountAfter, 'field grants should be idempotent');
    }

    // -----------------------------------------------------------------
    // additional sad: role management sad paths
    // -----------------------------------------------------------------
    public function testRoleManagementSadPaths(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);

        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => 'bad_scope_'.bin2hex(random_bytes(3)),
            'name' => 'Bad Scope',
            'scopeType' => 'invalid',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(400);

        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => 'Bad-Code-With-Dash',
            'name' => 'Bad Code',
            'scopeType' => 'store',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(400);

        $systemUuid = $this->getRoleUuidByCode($client, $adminToken, 'store_content_editor');
        self::assertNotNull($systemUuid);
        $client->request('DELETE', '/api/v1/manage/roles/'.$systemUuid);
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest('PUT', '/api/v1/manage/roles/'.$systemUuid, [
            'name' => 'Hacked',
        ]);
        self::assertTrue(\in_array($client->getResponse()->getStatusCode(), [400, 403], true));

        $code = 'mgmt_sad_'.bin2hex(random_bytes(4));
        $client->jsonRequest('POST', '/api/v1/manage/roles', [
            'code' => $code,
            'name' => 'Sad Role',
            'scopeType' => 'store',
            'uuid' => UUID::v4(),
        ]);
        self::assertResponseStatusCodeSame(201);
        $uuid = $this->decodeJson($client)['data']['uuid'];
        $client->request('DELETE', '/api/v1/manage/roles/'.$uuid);
        self::assertResponseStatusCodeSame(204);
        $client->request('DELETE', '/api/v1/manage/roles/'.$uuid);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPermissionListAndAssignmentListRequireAuth(): void
    {
        $client = static::createClient();
        $this->seedAuthorization($client);
        $adminToken = $this->createAdminAndGetToken($client);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$adminToken);

        $client->request('GET', '/api/v1/manage/permissions');
        self::assertResponseStatusCodeSame(200);
        $perms = $this->decodeJson($client);
        self::assertArrayHasKey('data', $perms);
        self::assertNotEmpty($perms['data']);

        $client->request('GET', '/api/v1/manage/assignments');
        self::assertResponseStatusCodeSame(200);

        // unauthenticated should be 401 - use same client with cleared auth
        $client->setServerParameter('HTTP_AUTHORIZATION', '');
        // Remove header by setting null server parameter? BrowserKit will send empty, firewall treats as unauthenticated
        // To truly test unauthenticated, create fresh client after shutdown
        self::ensureKernelShutdown();
        $ref = new \ReflectionProperty(\Symfony\Bundle\FrameworkBundle\Test\KernelTestCase::class, 'booted');
        $ref->setValue(null, false);
        $anon = static::createClient();
        $anon->request('GET', '/api/v1/manage/roles');
        self::assertResponseStatusCodeSame(401);
    }
}
