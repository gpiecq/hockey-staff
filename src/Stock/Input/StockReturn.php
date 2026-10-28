<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Enum\StockCondition;

/**
 * Retour d'une pièce prêtée, dans un lieu, avec son nouvel état.
 */
final readonly class StockReturn
{
    public function __construct(
        public Item $item,
        public Player $player,
        public Location $location,
        public StockCondition $condition,
        public ?string $comment = null,
    ) {
    }
}
