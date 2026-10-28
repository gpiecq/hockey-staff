<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Variant;
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

    /**
     * Tarif de référence pour le prix public : celui de la saison courante s'il a un prix public, sinon celui de la
     * saison la plus récente (startsOn) qui en a un, sinon null.
     */
    public function findPublicReference(Variant $variant, ?Season $current): ?SeasonPrice
    {
        if (null !== $current) {
            $price = $this->findOneBy(['variant' => $variant, 'season' => $current]);
            if (null !== $price?->getPublicPriceTtc()) {
                return $price;
            }
        }

        /** @var ?SeasonPrice $latest */
        $latest = $this->createQueryBuilder('sp')
            ->join('sp.season', 's')
            ->where('sp.variant = :variant')
            ->andWhere('sp.publicPriceTtc IS NOT NULL')
            ->setParameter('variant', $variant)
            ->orderBy('s.startsOn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $latest;
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
