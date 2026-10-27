<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Variant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Variant>
 */
class VariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Variant::class);
    }

    /** Variantes actives dont le produit n'est pas supprimé (le filtre softdeleteable s'applique à la jointure). */
    public function countActiveOfLiveProducts(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->innerJoin('v.product', 'p')
            ->where('v.active = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Variant> variantes non supprimées du produit, actives d'abord */
    public function findLiveByProduct(Product $product): array
    {
        /** @var list<Variant> $variants */
        $variants = $this->createQueryBuilder('v')
            ->where('v.product = :product')
            ->setParameter('product', $product)
            ->orderBy('v.active', 'DESC')
            ->addOrderBy('v.size')
            ->addOrderBy('v.fit')
            ->addOrderBy('v.side')
            ->addOrderBy('v.width')
            ->addOrderBy('v.color')
            ->addOrderBy('v.id')
            ->getQuery()
            ->getResult();

        return $variants;
    }
}
