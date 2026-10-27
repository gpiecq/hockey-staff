<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Enum\ItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /**
     * @return list<Location>
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC', 'id' => 'ASC']);
    }

    public function hasMovements(Location $location): bool
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(StockOperation::class, 'o')
            ->where('o.source = :location OR o.destination = :location')
            ->setParameter('location', $location)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Le lieu contient-il une pièce en stock ou un niveau de quantité > 0 ?
     */
    public function hasStock(Location $location): bool
    {
        $em = $this->getEntityManager();
        $items = (int) $em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Item::class, 'i')
            ->where('i.location = :location')
            ->andWhere('i.status = :status')
            ->setParameter('location', $location)
            ->setParameter('status', ItemStatus::InStock)
            ->getQuery()
            ->getSingleScalarResult();
        if ($items > 0) {
            return true;
        }

        return (int) $em->createQueryBuilder()
            ->select('COUNT(sl.id)')
            ->from(StockLevel::class, 'sl')
            ->where('sl.location = :location')
            ->andWhere('sl.quantity > 0')
            ->setParameter('location', $location)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
