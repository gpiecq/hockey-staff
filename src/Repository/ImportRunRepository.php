<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ImportRun> */
class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    /** Run le plus récent hors annulés (un run annulé n'apporte rien au tableau de bord). */
    public function findLatestNotCancelled(): ?ImportRun
    {
        /** @var ImportRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->where('r.status <> :cancelled')
            ->setParameter('cancelled', ImportRunStatus::Cancelled)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }
}
