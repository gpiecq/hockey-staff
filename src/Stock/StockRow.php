<?php

declare(strict_types=1);

namespace App\Stock;

use App\Entity\Variant;

final readonly class StockRow
{
    /**
     * @param array<int, int>                $quantityByLocation   id du lieu → quantité (physique uniquement) ; `quantity` et `valueHt` incluent les quantités prêtées (détentions des joueurs, sans filtre de lieu)
     * @param array<int, array<string, int>> $conditionsByLocation id du lieu → valeur d'état (`new`, `good`, `worn`, `damaged`) → quantité ; équipement seulement, lieux avec de l'occasion seulement, états dans l'ordre Neuf, Bon, Usé, Abîmé
     */
    public function __construct(
        public Variant $variant,
        public int $quantity,
        public int $valueHt,
        public array $quantityByLocation,
        public int $loanedQuantity = 0,
        public array $conditionsByLocation = [],
    ) {
    }
}
