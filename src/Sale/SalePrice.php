<?php

declare(strict_types=1);

namespace App\Sale;

/**
 * Prix d'une ligne de vente : montants en centimes, taux en points de base (20 % = 2000, réduction 80 % = 8000).
 */
final readonly class SalePrice
{
    public function __construct(
        public ?int $basePriceTtc,
        public ?int $discountRate,
        public int $vatRate,
        public int $priceTtc,
        public int $priceHt,
    ) {
    }
}
