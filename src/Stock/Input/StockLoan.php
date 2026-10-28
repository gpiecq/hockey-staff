<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Prêt d'équipement à un joueur : quantité prise dans le stock (lieu, état).
 */
final readonly class StockLoan
{
    public function __construct(
        public Location $location,
        public Player $player,
        public Variant $variant,
        public StockCondition $condition,
        public int $quantity,
        public ?string $comment = null,
    ) {
    }
}
