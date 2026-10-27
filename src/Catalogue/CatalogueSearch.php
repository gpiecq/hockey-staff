<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Entity\Product;
use App\Entity\Variant;
use App\Pagination\Page;
use App\Repository\ProductRepository;
use App\Repository\VariantRepository;

final readonly class CatalogueSearch
{
    public function __construct(
        private ProductRepository $products,
        private VariantRepository $variants,
    ) {
    }

    /** @return Page<Product> */
    public function search(CatalogueSearchCriteria $criteria): Page
    {
        $qb = $this->products->createQueryBuilder('p')
            ->addSelect('s', 'b', 'c')
            ->join('p.supplier', 's')
            ->leftJoin('p.brand', 'b')
            ->join('p.category', 'c')
            ->orderBy('p.designation')
            ->addOrderBy('p.id');

        $query = null === $criteria->query ? '' : trim($criteria->query);
        if ('' !== $query) {
            // LOWER + UNACCENT + LIKE : équivalent d'un ILIKE insensible aux accents ; % _ \ saisis sont échappés
            $like = '%'.addcslashes($query, '%_\\').'%';
            $qb->andWhere('LOWER(UNACCENT(p.designation)) LIKE LOWER(UNACCENT(:like)) OR LOWER(p.reference) LIKE LOWER(:like)')
                ->setParameter('like', $like);
        }

        if (null !== $criteria->categoryId) {
            // profondeur ≤ 3 : la catégorie filtrée est la catégorie du produit, son parent ou son grand-parent
            $qb->leftJoin('c.parent', 'cp')
                ->leftJoin('cp.parent', 'cg')
                ->andWhere('c.id = :category OR cp.id = :category OR cg.id = :category')
                ->setParameter('category', $criteria->categoryId);
        }

        if (null !== $criteria->supplierId) {
            $qb->andWhere('s.id = :supplier')->setParameter('supplier', $criteria->supplierId);
        }

        if (null !== $criteria->brandId) {
            $qb->andWhere('b.id = :brand')->setParameter('brand', $criteria->brandId);
        }

        if (!$criteria->includeInactive) {
            // masqué seulement s'il a au moins une variante vivante et qu'aucune n'est active
            $qb->andWhere('EXISTS (SELECT 1 FROM '.Variant::class.' va WHERE va.product = p AND va.active = true)
                OR NOT EXISTS (SELECT 1 FROM '.Variant::class.' vl WHERE vl.product = p)');
        }

        /** @var Page<Product> $page */
        $page = Page::fromQuery($qb, $criteria->page);

        return $page;
    }

    public function findVariantByEan(string $ean): ?Variant
    {
        $ean = trim($ean);
        if ('' === $ean) {
            return null;
        }

        $variant = $this->variants->createQueryBuilder('v')
            ->addSelect('p')
            ->join('v.product', 'p')
            ->where('v.ean = :ean')
            ->setParameter('ean', $ean)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert(null === $variant || $variant instanceof Variant);

        return $variant;
    }
}
