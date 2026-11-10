<?php

namespace App\Repository;

use App\Entity\TemporaryEmailBox;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TemporaryEmailBox>
 */
class TemporaryEmailBoxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemporaryEmailBox::class);
    }

    public function findOlderThan(\DateTimeImmutable $olderThan): array
    {
        return $this->createQueryBuilder('t')
            // Keep client addresses reserved after expiration to prevent delivery to a different client.
            ->where('t.expiresAt IS NULL AND t.createdAt < :olderThan')
            ->setParameter('olderThan', $olderThan)
            ->getQuery()
            ->getResult();
    }

    /** @return list<TemporaryEmailBox> */
    public function findClientInboxesByOwner(User $owner): array
    {
        return $this->createQueryBuilder('box')
            ->where('box.owner = :owner AND box.accessPasswordHash IS NOT NULL')
            ->setParameter('owner', $owner)
            ->orderBy('box.createdAt', 'DESC')
            ->addOrderBy('box.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<TemporaryEmailBox> */
    public function findDueMailcowProvisions(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.mailcowProvisionAfter <= :now AND t.expiresAt > :now')
            ->setParameter('now', $now)
            ->orderBy('t.mailcowProvisionAfter', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<array{countryCode: string, emailBoxCount: int|string}>
     */
    public function findTopCountriesByEmailBoxCount(int $limit = 5): array
    {
        return $this->createQueryBuilder('t')
            ->select('t.countryCode AS countryCode, COUNT(t.id) AS emailBoxCount')
            ->where('t.countryCode IS NOT NULL')
            ->groupBy('t.countryCode')
            ->orderBy('emailBoxCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}
