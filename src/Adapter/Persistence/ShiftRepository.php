<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use App\Domain\Entity\Shift;
use App\Domain\Repository\ShiftRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shift>
 */
final class ShiftRepository extends ServiceEntityRepository implements ShiftRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shift::class);
    }

    public function hasOverlap(Shift $shift): bool
    {
        return null !== $this->createQueryBuilder('shift')
            ->select('shift.id')
            ->andWhere('shift.employeeId = :employeeId')
            ->andWhere('shift.period.date = :date')
            ->andWhere('shift.period.startTime < :endTime')
            ->andWhere('shift.period.endTime > :startTime')
            ->setParameter('employeeId', $shift->employeeId)
            ->setParameter('date', $shift->period->date)
            ->setParameter('startTime', $shift->period->startTime)
            ->setParameter('endTime', $shift->period->endTime)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SCALAR);
    }

    public function save(Shift $shift): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($shift);
        $entityManager->flush();
    }
}
