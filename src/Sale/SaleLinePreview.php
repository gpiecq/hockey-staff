<?php

declare(strict_types=1);

namespace App\Sale;

use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Aperçu d'une ligne de vente avant enregistrement : prix public de référence, prix unitaire calculé et total de ligne,
 * ou motif d'impossibilité.
 */
final readonly class SaleLinePreview
{
    public function __construct(
        public Variant $variant,
        public StockCondition $condition,
        public int $quantity,
        public ?int $basePriceTtc,
        public ?SalePrice $price = null,
        public ?string $error = null,
    ) {
    }

    /** Total TTC de la ligne (quantité × prix unitaire TTC) ; null sans prix calculé. */
    public function totalTtc(): ?int
    {
        return null === $this->price ? null : $this->quantity * $this->price->priceTtc;
    }

    /** Total HT de la ligne (quantité × prix unitaire HT) ; null sans prix calculé. */
    public function totalHt(): ?int
    {
        return null === $this->price ? null : $this->quantity * $this->price->priceHt;
    }
}
