<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Prêt d'une pièce : l'appli prend la plus ancienne pièce de l'état demandé dans le lieu.
 */
final readonly class StockLoan
{
    public function __construct(
        public Location $location,
        public Player $player,
        public Variant $variant,
        public StockCondition $condition,
        public ?string $comment = null,
    ) {
    }
}
