<?php

namespace App\Repository;

use App\Entity\ReceivedEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReceivedEmail>
 */
class ReceivedEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReceivedEmail::class);
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoffDate): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.createdAt < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }

    /** @param non-empty-list<string> $domains
     *  @return list<ReceivedEmail>
     */
    public function findUnlinkedForMailcow(array $domains, \DateTimeImmutable $now): array
    {
        $query = $this->createQueryBuilder('r');
        $matchesDomain = $query->expr()->orX();
        foreach ($domains as $index => $domain) {
            $parameter = 'mailcowDomain' . $index;
            $matchesDomain->add($query->expr()->like('LOWER(r.realTo)', ':' . $parameter));
            $query->setParameter($parameter, '%@' . $domain);
        }

        return $query
            ->where('r.temporaryEmailBox IS NULL AND r.createdAt >= :cutoff')
            ->andWhere('r.mailcowLinkAfter IS NULL OR r.mailcowLinkAfter <= :now')
            ->andWhere($matchesDomain)
            ->setParameter('cutoff', $now->modify('-24 hours'))
            ->setParameter('now', $now)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }

    public function claimMailcowLink(int $id, \DateTimeImmutable $now): bool
    {
        return 1 === $this->createQueryBuilder('r')
            ->update()
            ->set('r.mailcowLinkAfter', ':retry')
            ->where('r.id = :id AND r.temporaryEmailBox IS NULL')
            ->andWhere('r.mailcowLinkAfter IS NULL OR r.mailcowLinkAfter <= :now')
            ->setParameter('id', $id)
            ->setParameter('now', $now)
            ->setParameter('retry', $now->modify('+5 minutes'))
            ->getQuery()
            ->execute();
    }

    public function deferMailcowLink(int $id, \DateTimeImmutable $after): void
    {
        $this->createQueryBuilder('r')
            ->update()
            ->set('r.mailcowLinkAfter', ':after')
            ->where('r.id = :id AND r.temporaryEmailBox IS NULL')
            ->setParameter('id', $id)
            ->setParameter('after', $after)
            ->getQuery()
            ->execute();
    }

    /** @return list<ReceivedEmail> */
    public function findDueMailcowCopies(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.mailcowCopyAfter <= :now')
            ->setParameter('now', $now)
            ->orderBy('r.mailcowCopyAfter', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    public function claimMailcowCopy(int $id, \DateTimeImmutable $now): bool
    {
        return 1 === $this->createQueryBuilder('r')
            ->update()
            ->set('r.mailcowCopyAfter', ':retry')
            ->set('r.mailcowCopyAttemptedAt', ':now')
            ->where('r.id = :id AND r.mailcowCopyAfter <= :now')
            ->setParameter('id', $id)
            ->setParameter('now', $now)
            ->setParameter('retry', $now->modify('+15 minutes'))
            ->getQuery()
            ->execute();
    }

    public function countWithTemporaryEmailBox(): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.temporaryEmailBox IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countWithoutTemporaryEmailBox(): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.temporaryEmailBox IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countReadEmails(): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.readAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadEmails(): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countEmailWithReadSubjects(): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.subjectReadAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByDomain(string $domain): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.realTo LIKE :domain')
            ->setParameter('domain', '%@' . $domain)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{countryCode: string, receivedEmailCount: int|string}>
     */
    public function findTopCountriesByReceivedEmailCount(int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->select('t.countryCode AS countryCode, COUNT(r.id) AS receivedEmailCount')
            ->innerJoin('r.temporaryEmailBox', 't')
            ->where('t.countryCode IS NOT NULL')
            ->groupBy('t.countryCode')
            ->orderBy('receivedEmailCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}
