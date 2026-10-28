<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sale;
use App\Entity\SaleLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sale>
 */
class SaleRepository extends ServiceEntityRepository
{
    use IncludesDeletedPeopleTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sale::class);
    }

    /**
     * Ventes, de la plus récente à la plus ancienne, avec leur opération (date, lieu).
     */
    public function listQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->addSelect('o')
            ->join('s.operation', 'o')
            ->orderBy('o.occurredAt', 'DESC')
            ->addOrderBy('s.id', 'DESC');
    }

    /**
     * Nombre de pièces par vente, en une seule requête.
     *
     * @param list<Sale> $sales
     *
     * @return array<int, int> id de vente => nombre de pièces
     */
    public function pieceCounts(array $sales): array
    {
        if ([] === $sales) {
            return [];
        }

        /** @var list<array{id: int|string, n: int|string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.sale) AS id', 'COUNT(l.id) AS n')
            ->from(SaleLine::class, 'l')
            ->where('l.sale IN (:sales)')
            ->setParameter('sales', $sales)
            ->groupBy('l.sale')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Vente pour sa fiche : opération, joueur (même supprimé), lignes, pièces et variantes jointes.
     */
    public function findForShow(int $id): ?Sale
    {
        $sale = $this->includingDeletedPeople(fn (): mixed => $this->createQueryBuilder('s')
            ->addSelect('o', 'pl', 'l', 'i', 'v', 'p')
            ->join('s.operation', 'o')
            ->leftJoin('s.player', 'pl')
            ->leftJoin('s.lines', 'l')
            ->leftJoin('l.item', 'i')
            ->leftJoin('i.variant', 'v')
            ->leftJoin('v.product', 'p')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult());

        return $sale instanceof Sale ? $sale : null;
    }
}
