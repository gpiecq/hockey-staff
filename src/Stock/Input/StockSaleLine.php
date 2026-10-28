<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Item;

/**
 * Ligne de vente : une pièce d'occasion, sa réduction (points de base) et/ou son prix final TTC (centimes).
 */
final readonly class StockSaleLine
{
    public function __construct(
        public Item $item,
        public ?int $discountRate,
        public ?int $finalPriceTtc,
    ) {
    }
}
