<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Ligne de vente : une quantité d'occasion (variante × état), sa réduction (points de base)
 * et/ou son prix final **unitaire** TTC (centimes).
 */
final readonly class StockSaleLine
{
    public function __construct(
        public Variant $variant,
        public StockCondition $condition,
        public int $quantity,
        public ?int $discountRate,
        public ?int $finalPriceTtc,
    ) {
    }
}
