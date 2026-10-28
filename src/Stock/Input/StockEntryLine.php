<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Ligne d'entrée : l'état est ignoré (forcé à Neuf) pour un consommable.
 */
final readonly class StockEntryLine
{
    public function __construct(
        public Variant $variant,
        public int $quantity,
        public ?int $unitCostHt = null,
        public StockCondition $condition = StockCondition::New,
    ) {
    }
}
