<?php

declare(strict_types=1);

namespace App\Stock;

use App\Entity\PlayerHolding;
use App\Entity\Product;
use App\Entity\StockLevel;
use App\Entity\StockMovement;
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
     * Variantes qui ont un niveau de stock positif (tout lieu, tout état) ou une détention de joueur (le matériel prêté appartient toujours au stock du club).
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
        $levels = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(l.variant) FROM '.StockLevel::class.' l WHERE IDENTITY(l.variant) IN (:ids) AND l.quantity > 0',
        )->setParameter('ids', $variantIds)->getSingleColumnResult();
        $held = $this->em->createQuery(
            'SELECT DISTINCT IDENTITY(h.variant) FROM '.PlayerHolding::class.' h WHERE IDENTITY(h.variant) IN (:ids)',
        )->setParameter('ids', $variantIds)->getSingleColumnResult();
        foreach ([...$levels, ...$held] as $id) {
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

    /** Vrai si le produit a un niveau de stock positif ou une détention de joueur (c'est ce qui verrouille son type). */
    public function productHasStock(Product $product): bool
    {
        if (null === $product->getId()) {
            return false;
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
