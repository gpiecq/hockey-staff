<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\Variant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockLevel>
 */
class StockLevelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockLevel::class);
    }

    /**
     * Verrouille le niveau (PESSIMISTIC_WRITE) ; à appeler dans une transaction.
     */
    public function findForUpdate(Variant $variant, Location $location): ?StockLevel
    {
        $level = $this->createQueryBuilder('sl')
            ->where('sl.variant = :variant')
            ->andWhere('sl.location = :location')
            ->setParameter('variant', $variant)
            ->setParameter('location', $location)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
        \assert(null === $level || $level instanceof StockLevel);

        return $level;
    }
}
