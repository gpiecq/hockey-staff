<?php

declare(strict_types=1);

namespace App\Stock;

use App\Entity\Item;
use App\Entity\PlayerHolding;
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
     * Variantes qui ont une pièce en stock ou prêtée (elle appartient toujours au stock du club), un niveau de quantité positif ou une détention de joueur.
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
        $held = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(h.variant) FROM '.PlayerHolding::class.' h WHERE IDENTITY(h.variant) IN (:ids)',
        )->setParameter('ids', $variantIds)->getSingleColumnResult();
        foreach ([...$items, ...$levels, ...$held] as $id) {
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

    /** Vrai si le produit a une pièce en stock ou prêtée, un niveau de quantité positif ou une détention de joueur (c'est ce qui verrouille son type). */
    public function productHasStock(Product $product): bool
    {
        if (null === $product->getId()) {
            return false;
        }

        $items = (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM '.Item::class.' i JOIN i.variant v WHERE IDENTITY(v.product) = :product AND i.status IN (:statuses)',
        )->setParameter('product', $product->getId())->setParameter('statuses', [ItemStatus::InStock, ItemStatus::Loaned])->getSingleScalarResult();
        if ($items > 0) {
            return true;
        }

        $levels = (int) $this->em->createQuery(
            'SELECT COUNT(l.id) FROM '.StockLevel::class.' l JOIN l.variant v WHERE IDENTITY(v.product) = :product AND l.quantity > 0',
        )->setParameter('product', $product->getId())->getSingleScalarResult();
        if ($levels > 0) {
            return true;
        }

        return (int) $this->em->createQuery(
            'SELECT COUNT(h.id) FROM '.PlayerHolding::class.' h JOIN h.variant v WHERE IDENTITY(v.product) = :product',
        )->setParameter('product', $product->getId())->getSingleScalarResult() > 0;
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
