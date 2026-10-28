<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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
     * Verrouille le niveau (variante, lieu, état) en PESSIMISTIC_WRITE ; à appeler dans une transaction.
     */
    public function findForUpdate(Variant $variant, Location $location, StockCondition $condition = StockCondition::New): ?StockLevel
    {
        $level = $this->createQueryBuilder('sl')
            ->where('sl.variant = :variant')
            ->andWhere('sl.location = :location')
            ->andWhere('sl.condition = :condition')
            ->setParameter('variant', $variant)
            ->setParameter('location', $location)
            ->setParameter('condition', $condition)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $level || $level instanceof StockLevel);

        return $level;
    }

    /**
     * Quantités disponibles par état d'une variante dans un lieu (états à quantité nulle omis).
     *
     * @return array<string, int> valeur d'état => quantité
     */
    public function quantitiesByCondition(Variant $variant, Location $location): array
    {
        /** @var list<array{levelCondition: StockCondition|string, quantity: int|string}> $rows */
        $rows = $this->createQueryBuilder('sl')
            ->select('sl.condition AS levelCondition', 'sl.quantity AS quantity')
            ->where('sl.variant = :variant')
            ->andWhere('sl.location = :location')
            ->andWhere('sl.quantity > 0')
            ->setParameter('variant', $variant)
            ->setParameter('location', $location)
            ->getQuery()
            ->getResult();

        $quantities = [];
        foreach ($rows as $row) {
            $condition = $row['levelCondition'];
            $quantities[$condition instanceof StockCondition ? $condition->value : $condition] = (int) $row['quantity'];
        }

        return $quantities;
    }

    /**
     * Occasion vendable : niveaux d'équipement d'état autre que Neuf, en quantité, dans le lieu donné (tous lieux si null) ;
     * variante, produit et lieu joints ; tri désignation, variante (clé de rapprochement), lieu, puis état (Bon, Usé, Abîmé).
     *
     * @return list<StockLevel>
     */
    public function findSecondHand(?Location $location): array
    {
        $qb = $this->createQueryBuilder('sl')
            ->addSelect('v', 'p', 'l')
            ->addSelect("CASE sl.condition WHEN 'good' THEN 1 WHEN 'worn' THEN 2 ELSE 3 END AS HIDDEN conditionRank")
            ->join('sl.variant', 'v')
            ->join('v.product', 'p')
            ->join('sl.location', 'l')
            ->where('p.kind = :equipment')
            ->andWhere('sl.condition <> :new')
            ->andWhere('sl.quantity > 0')
            ->setParameter('equipment', ProductKind::Equipment)
            ->setParameter('new', StockCondition::New)
            ->orderBy('p.designation')
            ->addOrderBy('v.matchKey')
            ->addOrderBy('v.id')
            ->addOrderBy('l.name')
            ->addOrderBy('l.id')
            ->addOrderBy('conditionRank');
        if (null !== $location) {
            $qb->andWhere('sl.location = :location')->setParameter('location', $location);
        }
        /** @var list<StockLevel> $levels */
        $levels = $qb->getQuery()->getResult();

        return $levels;
    }
}
