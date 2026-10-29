<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\App\Trade\Entity\Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findById(int $id): ?Order
    {
        return $this->find($id);
    }

    /** Read current state under the order lock, not a cached entity or snapshot. */
    public function findByUuidForUpdate(string $uuid): ?Order
    {
        $query = $this->createQueryBuilder('o')
            ->where('o.uuid = :uuid')->setParameter('uuid', $uuid)->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        if (!$this->getEntityManager()->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getOneOrNullResult();
    }

    /**
     * @return list<Order>
     */
    public function findByUser(int $userId): array
    {
        return $this->findBy(['user' => $userId], ['createdAt' => 'DESC']);
    }
}
