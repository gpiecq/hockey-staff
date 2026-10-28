<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Retour de prêt : quantité rendue par le joueur, remise en stock au lieu choisi dans le nouvel état.
 */
final readonly class StockReturn
{
    public function __construct(
        public Player $player,
        public Variant $variant,
        public int $quantity,
        public Location $location,
        public StockCondition $condition,
        public ?string $comment = null,
    ) {
    }
}
