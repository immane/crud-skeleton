<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Store\Service;

use App\Store\Entity\Store;
use App\Store\Repository\StoreRepository;
use App\Store\Service\StoreService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AllowMockObjectsWithoutExpectations]
final class StoreServiceTest extends TestCase
{
    public function testCreateStoreValidatesInputAndPersistsUniqueStore(): void
    {
        $repository = $this->createMock(StoreRepository::class);
        $repository->method('findOneByCode')->willReturn(null);
        $entityManager = $this->createEntityManager($repository);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Store::class));
        $service = new StoreService($this->createContainer($entityManager), $repository);

        $store = $service->createStore('demo', 'Demo Store', 'Asia/Shanghai');

        self::assertSame('demo', $store->getCode());
        self::assertSame('Asia/Shanghai', $store->getTimezone());
    }

    public function testCreateStoreRejectsInvalidAndDuplicateValues(): void
    {
        $repository = $this->createMock(StoreRepository::class);
        $entityManager = $this->createEntityManager($repository);
        $service = new StoreService($this->createContainer($entityManager), $repository);

        $this->expectException(\InvalidArgumentException::class);
        $service->createStore(' ', 'Demo Store', 'Asia/Shanghai');
    }

    public function testCreateStoreRejectsInvalidTimezoneAndDuplicateCode(): void
    {
        $repository = $this->createMock(StoreRepository::class);
        $existingStore = null;
        $repository->method('findOneByCode')->willReturnCallback(function () use (&$existingStore): ?Store {
            return $existingStore;
        });
        $entityManager = $this->createEntityManager($repository);
        $service = new StoreService($this->createContainer($entityManager), $repository);

        try {
            $service->createStore('demo', 'Demo Store', 'Invalid/Timezone');
            self::fail('Expected invalid timezone exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Store timezone must be a valid IANA timezone.', $exception->getMessage());
        }

        $existingStore = new Store('demo', 'Existing Store', 'Asia/Shanghai');
        $this->expectException(\LogicException::class);
        $service->createStore('demo', 'Demo Store', 'Asia/Shanghai');
    }

    public function testFindActiveByUuidExcludesInactiveStores(): void
    {
        $active = new Store('active', 'Active', 'UTC');
        $inactive = new Store('inactive', 'Inactive', 'UTC');
        $inactive->suspend();
        $repository = $this->createMock(StoreRepository::class);
        $repository->method('findOneByUuid')->willReturnMap([
            ['active', $active],
            ['inactive', $inactive],
            ['missing', null],
        ]);
        $service = new StoreService($this->createContainer($this->createEntityManager($repository)), $repository);

        self::assertSame($active, $service->findActiveByUuid('active'));
        self::assertNull($service->findActiveByUuid('inactive'));
        self::assertNull($service->findActiveByUuid('missing'));
    }

    private function createEntityManager(StoreRepository $repository): EntityManagerInterface
    {
        $connection = $this->createMock(Connection::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Store::class)->willReturn($repository);
        $entityManager->method('getConnection')->willReturn($connection);
        return $entityManager;
    }

    private function createContainer(EntityManagerInterface $entityManager): ContainerInterface
    {
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
