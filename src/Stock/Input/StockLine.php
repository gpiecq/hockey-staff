<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Ligne de sortie ou de transfert : l'état est ignoré (forcé à Neuf) pour un consommable.
 */
final readonly class StockLine
{
    public function __construct(
        public Variant $variant,
        public int $quantity,
        public StockCondition $condition = StockCondition::New,
    ) {
    }
}
