<?php

declare(strict_types=1);

namespace App\Trade\Repository;

use App\Trade\Entity\TradeConsumedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TradeConsumedEvent> */
class TradeConsumedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeConsumedEvent::class);
    }

    public function findOneByEventId(string $eventId): ?TradeConsumedEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }
}
