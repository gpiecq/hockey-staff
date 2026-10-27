<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Location;
use App\Entity\StockMovement;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\StockMovementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockOperation>
 */
class StockOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockOperation::class);
    }

    /**
     * Opérations filtrées, de la plus récente à la plus ancienne. Le lieu filtre sur la source ou la destination ;
     * la variante, sur les opérations qui ont au moins un mouvement sur elle (EXISTS : pas de doublons).
     * La date de fin est incluse (jusqu'à la fin de la journée).
     */
    public function search(?StockMovementType $type, ?Location $location, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?Variant $variant): QueryBuilder
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.source', 'src')->addSelect('src')
            ->leftJoin('o.destination', 'dst')->addSelect('dst')
            ->leftJoin('o.createdBy', 'author')->addSelect('author')
            ->orderBy('o.occurredAt', 'DESC')
            ->addOrderBy('o.id', 'DESC');

        if (null !== $type) {
            $qb->andWhere('o.type = :type')->setParameter('type', $type);
        }
        if (null !== $location) {
            $qb->andWhere('o.source = :location OR o.destination = :location')->setParameter('location', $location);
        }
        if (null !== $from) {
            $qb->andWhere('o.occurredAt >= :from')->setParameter('from', $from->setTime(0, 0));
        }
        if (null !== $to) {
            $qb->andWhere('o.occurredAt < :toExclusive')->setParameter('toExclusive', $to->setTime(0, 0)->modify('+1 day'));
        }
        if (null !== $variant) {
            $qb->andWhere(\sprintf('EXISTS (SELECT 1 FROM %s m WHERE m.operation = o AND m.variant = :variant)', StockMovement::class))
                ->setParameter('variant', $variant);
        }

        return $qb;
    }

    /**
     * Nombre de lignes par opération, en une seule requête.
     *
     * @param list<StockOperation> $operations
     *
     * @return array<int, int> id d'opération => nombre de lignes
     */
    public function lineCounts(array $operations): array
    {
        if ([] === $operations) {
            return [];
        }

        /** @var list<array{id: int|string, n: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(m.operation) AS id', 'COUNT(m.id) AS n')
            ->from(StockMovement::class, 'm')
            ->where('m.operation IN (:operations)')
            ->setParameter('operations', $operations)
            ->groupBy('m.operation')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }
}
