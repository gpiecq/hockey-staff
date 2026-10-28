<?php

declare(strict_types=1);

namespace App\Sale;

use App\Repository\ItemRepository;
use App\Stock\StockException;

/**
 * Calcule, sans rien enregistrer, le prix de chaque ligne saisie dont le code désigne une pièce.
 * Sans réduction ni prix final, seul le prix public de référence est donné.
 */
final readonly class SalePreviewer
{
    public function __construct(
        private ItemRepository $items,
        private SalePricing $pricing,
    ) {
    }

    /**
     * @param array<array-key, mixed> $lines données des lignes du formulaire (itemCode, discount en %, finalPrice en centimes)
     *
     * @return array<array-key, SaleLinePreview> aperçus indexés comme les lignes ; lignes sans pièce reconnue absentes
     */
    public function preview(array $lines): array
    {
        $previews = [];
        foreach ($lines as $key => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \is_string($row['itemCode'] ?? null) ? trim($row['itemCode']) : '';
            $item = '' === $code ? null : $this->items->findOneByCode($code);
            if (null === $item) {
                continue;
            }
            $variant = $item->getVariant();
            $discount = \is_int($row['discount'] ?? null) ? $row['discount'] * 100 : null;
            $finalPrice = \is_int($row['finalPrice'] ?? null) ? $row['finalPrice'] : null;
            $base = $this->pricing->publicReferencePrice($variant);
            if (null === $discount && null === $finalPrice) {
                $previews[$key] = new SaleLinePreview($item, $base);
                continue;
            }
            try {
                $previews[$key] = new SaleLinePreview($item, $base, $this->pricing->price($variant, $discount, $finalPrice));
            } catch (StockException $e) {
                $previews[$key] = new SaleLinePreview($item, $base, error: $e->getMessage());
            }
        }

        return $previews;
    }

    /**
     * Totaux TTC et HT, seulement si chaque ligne saisie a un prix calculé.
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
            $price = ($previews[$key] ?? null)?->price;
            if (null === $price) {
                return null;
            }
            $totals['ttc'] += $price->priceTtc;
            $totals['ht'] += $price->priceHt;
        }

        return $totals;
    }
}
