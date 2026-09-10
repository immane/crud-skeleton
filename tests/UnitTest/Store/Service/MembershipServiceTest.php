<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Service;

use App\Store\Entity\Store;
use App\Store\Entity\Membership;
use App\Store\Repository\MembershipRepository;
use App\Store\Service\MembershipService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AllowMockObjectsWithoutExpectations]
final class MembershipServiceTest extends TestCase
{
    public function testAuthorizationRequiresAnActiveMembershipWithAnAllowedRole(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $membership = new Membership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn($membership);

        $service = new MembershipService($this->createContainer($repository), $repository);

        self::assertTrue($service->isAuthorized($store, $membership->getUserUuid(), [Membership::ROLE_MANAGER]));
        self::assertFalse($service->isAuthorized($store, $membership->getUserUuid(), [Membership::ROLE_FULFILLMENT]));

        $membership->revoke();
        self::assertFalse($service->isAuthorized($store, $membership->getUserUuid()));
    }

    public function testRequireAuthorizationReturnsMembershipOrDeniesAccess(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $membership = new Membership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn($membership);
        $service = new MembershipService($this->createContainer($repository), $repository);

        self::assertSame($membership, $service->requireAuthorization($store, $membership->getUserUuid(), [Membership::ROLE_MANAGER]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Store membership authorization denied.');
        $service->requireAuthorization($store, $membership->getUserUuid(), [Membership::ROLE_OWNER]);
    }

    public function testRequireAuthorizationDeniesMissingMembership(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn(null);
        $service = new MembershipService($this->createContainer($repository), $repository);

        $this->expectException(\RuntimeException::class);
        $service->requireAuthorization($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57');
    }

    public function testGrantRejectsBlankUserUuid(): void
    {
        $repository = $this->createMock(MembershipRepository::class);
        $service = new MembershipService($this->createContainer($repository), $repository);
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Store membership user UUID is required.');
        $service->grant($store, '   ', Membership::ROLE_MANAGER);
    }

    public function testGrantRequiresPersistedStore(): void
    {
        $repository = $this->createMock(MembershipRepository::class);
        $service = new MembershipService($this->createContainer($repository), $repository);
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Store must be persisted before granting membership.');
        $service->grant($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);
    }

    public function testGrantCreatesNewMembershipWhenNoneExists(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $this->assignStoreId($store, 7);
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Membership::class)->willReturn($repository);
        $entityManager->method('getReference')->with(Store::class, 7)->willReturn($store);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Membership::class));
        $service = new MembershipService($this->createContainer($repository, $entityManager), $repository);

        $membership = $service->grant($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);

        self::assertSame(Membership::ROLE_MANAGER, $membership->getRole());
        self::assertSame($store, $membership->getStore());
    }

    public function testGrantUpdatesExistingMembership(): void
    {
        $store = new Store('demo', 'Demo', 'Asia/Shanghai');
        $this->assignStoreId($store, 7);
        $existing = new Membership($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_CLERK);
        $existing->revoke();
        $repository = $this->createMock(MembershipRepository::class);
        $repository->method('findForStoreAndUser')->with($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57')->willReturn($existing);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Membership::class)->willReturn($repository);
        $entityManager->method('getReference')->with(Store::class, 7)->willReturn($store);
        $service = new MembershipService($this->createContainer($repository, $entityManager), $repository);

        $granted = $service->grant($store, '47d07ad3-7e6e-4bfb-aea3-87bdb0e4de57', Membership::ROLE_MANAGER);

        self::assertSame($existing, $granted);
        self::assertSame(Membership::ROLE_MANAGER, $existing->getRole());
        self::assertTrue($existing->isActive());
    }

    private function assignStoreId(Store $store, int $id): void
    {
        (new \ReflectionProperty(Store::class, 'id'))->setValue($store, $id);
    }

    private function createContainer(MembershipRepository $repository, ?EntityManagerInterface $entityManager = null): ContainerInterface
    {
        $entityManager ??= $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Membership::class)->willReturn($repository);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(fn (string $id): mixed => match ($id) {
            'doctrine.orm.entity_manager' => $entityManager,
            'logger' => $this->createMock(LoggerInterface::class),
            'security.token_storage' => $this->createMock(TokenStorageInterface::class),
            default => null,
        });

        return $container;
    }
}
