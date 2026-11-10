<?php

namespace App\Repository;

use App\Entity\N8nEmailDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<N8nEmailDelivery> */
final class N8nEmailDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, N8nEmailDelivery::class);
    }

    /** @return list<N8nEmailDelivery> */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('delivery')
            ->addSelect('email')
            ->innerJoin('delivery.email', 'email')
            ->where('delivery.nextAttemptAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('delivery.nextAttemptAt', 'ASC')
            ->addOrderBy('delivery.id', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    public function claim(int $id, \DateTimeImmutable $now, \DateTimeImmutable $leaseUntil): bool
    {
        return 1 === $this->createQueryBuilder('delivery')
            ->update()
            ->set('delivery.nextAttemptAt', ':lease')
            ->where('delivery.id = :id AND delivery.nextAttemptAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('now', $now)
            ->setParameter('lease', $leaseUntil)
            ->getQuery()
            ->execute();
    }

    public function markDelivered(int $id, \DateTimeImmutable $leaseUntil): void
    {
        $this->createQueryBuilder('delivery')
            ->delete()
            ->where('delivery.id = :id AND delivery.nextAttemptAt = :lease')
            ->setParameter('id', $id)
            ->setParameter('lease', $leaseUntil)
            ->getQuery()
            ->execute();
    }

    public function markFailed(int $id, \DateTimeImmutable $leaseUntil, ?\DateTimeImmutable $retryAt): void
    {
        $this->createQueryBuilder('delivery')
            ->update()
            ->set('delivery.failedAttempts', 'delivery.failedAttempts + 1')
            ->set('delivery.nextAttemptAt', ':retry')
            ->where('delivery.id = :id AND delivery.nextAttemptAt = :lease')
            ->setParameter('id', $id)
            ->setParameter('lease', $leaseUntil)
            ->setParameter('retry', $retryAt)
            ->getQuery()
            ->execute();
    }
}
