<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Variant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** Produits non supprimés (filtre softdeleteable) sans catégorie. */
    public function countWithoutCategory(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.category IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Produits non supprimés ayant au moins une variante active sans prix club pour la saison (0 est un prix). */
    public function countMissingClubPrice(Season $season): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->andMissingClubPrice($qb, 'p', (int) $season->getId());

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Définition unique de « produit sans tarif club », partagée par le comptage et le filtre du catalogue. */
    public function andMissingClubPrice(QueryBuilder $qb, string $productAlias, int $seasonId): void
    {
        $qb->andWhere('EXISTS (SELECT 1 FROM '.Variant::class.' mv WHERE mv.product = '.$productAlias.' AND mv.active = true
                AND NOT EXISTS (SELECT 1 FROM '.SeasonPrice::class.' mp WHERE mp.variant = mv AND mp.season = :missingPriceSeason AND mp.clubPriceTtc IS NOT NULL))')
            ->setParameter('missingPriceSeason', $seasonId);
    }
}
