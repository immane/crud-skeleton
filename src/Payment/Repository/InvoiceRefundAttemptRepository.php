<?php

declare(strict_types=1);

namespace App\Payment\Repository;

use App\Payment\Entity\Invoice;
use App\Payment\Entity\InvoiceRefundAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InvoiceRefundAttempt> */
final class InvoiceRefundAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceRefundAttempt::class);
    }

    /** The caller must first lock the source aggregate and invoice. */
    public function findCurrent(string $key): ?InvoiceRefundAttempt
    {
        $query = $this->createQueryBuilder('attempt')
            ->where('attempt.attemptKey = :key')->setParameter('key', $key)->getQuery();

        return $this->currentRead($query)->getOneOrNullResult();
    }

    /** @return list<InvoiceRefundAttempt> */
    public function unresolved(string $invoiceUuid): array
    {
        $query = $this->createQueryBuilder('attempt')
            ->where('attempt.invoiceUuid = :invoice')->setParameter('invoice', $invoiceUuid)
            ->andWhere('attempt.status IN (:statuses)')->setParameter('statuses', self::unresolvedStatuses())
            ->getQuery();

        return $this->currentRead($query)->getResult();
    }

    public function hasUnresolvedForSource(string $type, string $id): bool
    {
        $query = $this->createQueryBuilder('attempt')
            ->innerJoin(Invoice::class, 'invoice', 'WITH', 'invoice.uuid = attempt.invoiceUuid')
            ->where('invoice.sourceType = :type')->setParameter('type', $type)
            ->andWhere('invoice.sourceId = :id')->setParameter('id', $id)
            ->andWhere('attempt.status IN (:statuses)')->setParameter('statuses', self::unresolvedStatuses())
            ->setMaxResults(1)->getQuery();

        return $this->currentRead($query)->getOneOrNullResult() !== null;
    }

    /**
     * @template TKey of array-key|null
     * @template TResult
     * @param Query<TKey, TResult> $query
     * @return Query<TKey, TResult>
     */
    private function currentRead(Query $query): Query
    {
        // A refresh without a lock still reads an old MySQL REPEATABLE READ snapshot.
        // Outside a transaction this is only an advisory workflow availability check;
        // mutation callers recheck while holding the order lock inside a transaction.
        $connection = $this->getEntityManager()->getConnection();
        if ($connection->isTransactionActive() && !$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->setHint(Query::HINT_REFRESH, true);
    }

    /** @return list<string> */
    private static function unresolvedStatuses(): array
    {
        return [InvoiceRefundAttempt::STATUS_PROCESSING, InvoiceRefundAttempt::STATUS_UNKNOWN];
    }
}
