<?php

declare(strict_types=1);

namespace App\Stock;

use App\Entity\Variant;

final readonly class StockRow
{
    /**
     * @param array<int, int> $quantityByLocation id du lieu → quantité (physique uniquement) ; `quantity` et `valueHt` incluent les pièces prêtées
     */
    public function __construct(
        public Variant $variant,
        public int $quantity,
        public int $valueHt,
        public array $quantityByLocation,
        public int $loanedQuantity = 0,
    ) {
    }
}
