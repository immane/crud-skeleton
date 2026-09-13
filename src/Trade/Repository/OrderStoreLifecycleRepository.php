<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OrderStoreLifecycle> */
class OrderStoreLifecycleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderStoreLifecycle::class);
    }

    public function findOneByTradeOrderUuid(string $tradeOrderUuid): ?OrderStoreLifecycle
    {
        return $this->findOneBy(['tradeOrderUuid' => $tradeOrderUuid]);
    }
}
