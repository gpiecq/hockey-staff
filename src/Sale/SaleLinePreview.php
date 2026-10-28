<?php

declare(strict_types=1);

namespace App\Sale;

use App\Entity\Item;

/**
 * Aperçu d'une ligne de vente avant enregistrement : prix public de référence, prix calculé, ou motif d'impossibilité.
 */
final readonly class SaleLinePreview
{
    public function __construct(
        public Item $item,
        public ?int $basePriceTtc,
        public ?SalePrice $price = null,
        public ?string $error = null,
    ) {
    }
}
