<?php

declare(strict_types=1);

namespace App\Sale;

use App\Form\Sale\SaleLineMapper;
use App\Stock\StockException;

/**
 * Calcule, sans rien enregistrer, le prix unitaire et le total de chaque ligne saisie dont l'article est reconnu.
 * Sans réduction ni prix final, seul le prix public de référence est donné.
 */
final readonly class SalePreviewer
{
    public function __construct(
        private SaleLineMapper $mapper,
        private SalePricing $pricing,
    ) {
    }

    /**
     * @param array<array-key, mixed> $lines données des lignes du formulaire (stock, quantity, discount en %, finalPrice en centimes)
     *
     * @return array<array-key, SaleLinePreview> aperçus indexés comme les lignes ; lignes sans article reconnu absentes
     */
    public function preview(array $lines): array
    {
        $previews = [];
        foreach ($lines as $key => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $stock = $this->mapper->parse($row['stock'] ?? null);
            if (null === $stock) {
                continue;
            }
            [$variant, $condition] = $stock;
            $quantity = \is_int($row['quantity'] ?? null) && $row['quantity'] > 0 ? $row['quantity'] : 1;
            $discount = \is_int($row['discount'] ?? null) ? $row['discount'] * 100 : null;
            $finalPrice = \is_int($row['finalPrice'] ?? null) ? $row['finalPrice'] : null;
            $base = $this->pricing->publicReferencePrice($variant);
            if (null === $discount && null === $finalPrice) {
                $previews[$key] = new SaleLinePreview($variant, $condition, $quantity, $base);
                continue;
            }
            try {
                $previews[$key] = new SaleLinePreview($variant, $condition, $quantity, $base, $this->pricing->price($variant, $discount, $finalPrice));
            } catch (StockException $e) {
                $previews[$key] = new SaleLinePreview($variant, $condition, $quantity, $base, error: $e->getMessage());
            }
        }

        return $previews;
    }

    /**
     * Totaux TTC et HT (somme des totaux de ligne), seulement si chaque ligne saisie a un prix calculé.
     *
     * @param array<array-key, mixed>           $lines
     * @param array<array-key, SaleLinePreview> $previews
     *
     * @return array{ttc: int, ht: int}|null
     */
    public function totals(array $lines, array $previews): ?array
    {
        if ([] === $lines) {
            return null;
        }
        $totals = ['ttc' => 0, 'ht' => 0];
        foreach (array_keys($lines) as $key) {
            $preview = $previews[$key] ?? null;
            $ttc = $preview?->totalTtc();
            $ht = $preview?->totalHt();
            if (null === $ttc || null === $ht) {
                return null;
            }
            $totals['ttc'] += $ttc;
            $totals['ht'] += $ht;
        }

        return $totals;
    }
}
