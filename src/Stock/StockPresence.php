<?php

declare(strict_types=1);

namespace App\Stock;

use App\Entity\Item;
use App\Entity\Product;
use App\Entity\StockLevel;
use App\Entity\StockMovement;
use App\Enum\ItemStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Questions de présence de stock posées au catalogue et à l'import, par lots d'identifiants de variantes
 * (une requête par question, jamais une par variante).
 */
final readonly class StockPresence
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Variantes qui ont une pièce en stock ou prêtée (elle appartient toujours au stock du club) ou un niveau de quantité positif.
     *
     * @param list<int> $variantIds
     *
     * @return array<int, true>
     */
    public function variantIdsInStock(array $variantIds): array
    {
        if ([] === $variantIds) {
            return [];
        }

        $found = [];
        $items = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(i.variant) FROM '.Item::class.' i WHERE IDENTITY(i.variant) IN (:ids) AND i.status IN (:statuses)',
        )->setParameter('ids', $variantIds)->setParameter('statuses', [ItemStatus::InStock, ItemStatus::Loaned])->getSingleColumnResult();
        $levels = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(l.variant) FROM '.StockLevel::class.' l WHERE IDENTITY(l.variant) IN (:ids) AND l.quantity > 0',
        )->setParameter('ids', $variantIds)->getSingleColumnResult();
        foreach ([...$items, ...$levels] as $id) {
            \assert(\is_int($id) || \is_string($id));
            $found[(int) $id] = true;
        }

        return $found;
    }

    /**
     * Variantes qui ont au moins un mouvement de stock.
     *
     * @param list<int> $variantIds
     *
     * @return array<int, true>
     */
    public function variantIdsWithMovements(array $variantIds): array
    {
        if ([] === $variantIds) {
            return [];
        }

        $found = [];
        $ids = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(m.variant) FROM '.StockMovement::class.' m WHERE IDENTITY(m.variant) IN (:ids)',
        )->setParameter('ids', $variantIds)->getSingleColumnResult();
        foreach ($ids as $id) {
            \assert(\is_int($id) || \is_string($id));
            $found[(int) $id] = true;
        }

        return $found;
    }

    /** Vrai si une variante du produit (vivante : les variantes avec mouvements ne se suppriment pas) a un mouvement de stock. */
    public function productHasMovements(Product $product): bool
    {
        if (null === $product->getId()) {
            return false;
        }

        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM '.StockMovement::class.' m JOIN m.variant v WHERE IDENTITY(v.product) = :product',
        )->setParameter('product', $product->getId())->getSingleScalarResult() > 0;
    }
}
