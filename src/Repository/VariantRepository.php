<?php

declare(strict_types=1);

namespace App\Repository;

use App\Catalogue\Ean;
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

    public function nextCode(): string
    {
        $next = $this->getEntityManager()->getConnection()->fetchOne("SELECT nextval('variant_code_seq')");

        return \sprintf('HV-%06d', is_numeric($next) ? (int) $next : throw new \LogicException('variant_code_seq indisponible.'));
    }

    /**
     * Variante par code HV-… (espaces ignorés, insensible à la casse), y compris supprimée si le filtre est désactivé.
     */
    public function findOneByCode(string $code): ?Variant
    {
        $variant = $this->findOneBy(['code' => strtoupper(trim($code))]);

        return $variant instanceof Variant ? $variant : null;
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

    /**
     * Pour UniqueEntity : variantes vivantes (filtre softdeleteable) portant le même code, 12 ou 13 chiffres.
     *
     * @param array{ean: string} $criteria
     *
     * @return list<Variant>
     */
    public function findByEanEquivalent(array $criteria): array
    {
        /** @var list<Variant> $variants */
        $variants = $this->createQueryBuilder('v')
            ->where('v.ean IN (:candidates)')
            ->setParameter('candidates', Ean::candidates($criteria['ean']))
            ->getQuery()
            ->getResult();

        return $variants;
    }
}
