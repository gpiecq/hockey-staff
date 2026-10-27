<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;
use App\Entity\StockMovement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockMovement>
 */
class StockMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovement::class);
    }

    /**
     * Historique d'une pièce, du plus récent au plus ancien.
     *
     * @return list<StockMovement>
     */
    public function findByItem(Item $item): array
    {
        /** @var list<StockMovement> $movements */
        $movements = $this->createQueryBuilder('m')
            ->addSelect('o', 's', 'd')
            ->join('m.operation', 'o')
            ->leftJoin('o.source', 's')
            ->leftJoin('o.destination', 'd')
            ->where('m.item = :item')
            ->setParameter('item', $item)
            ->orderBy('o.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $movements;
    }
}
