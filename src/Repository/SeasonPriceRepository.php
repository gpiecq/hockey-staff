<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonPrice>
 */
class SeasonPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonPrice::class);
    }

    /** @return array<int, SeasonPrice> tarifs de la saison pour les variantes du produit, indexés par id de variante */
    public function indexByVariantForProduct(Product $product, Season $season): array
    {
        /** @var list<SeasonPrice> $prices */
        $prices = $this->createQueryBuilder('sp')
            ->join('sp.variant', 'v')
            ->where('v.product = :product')
            ->andWhere('sp.season = :season')
            ->setParameter('product', $product)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($prices as $price) {
            $variantId = $price->getVariant()?->getId();
            if (null !== $variantId) {
                $indexed[$variantId] = $price;
            }
        }

        return $indexed;
    }
}
