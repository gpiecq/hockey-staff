<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Team;
use App\Entity\Variant;
use App\Enum\StockCondition;

/**
 * Don à un joueur ou à une équipe ; `$condition` est requis pour un équipement, ignoré (Neuf) pour un consommable.
 */
final readonly class StockGift
{
    public function __construct(
        public Location $location,
        public Variant $variant,
        public int $quantity,
        public ?StockCondition $condition,
        public ?Player $player,
        public ?Team $team,
        public ?string $comment = null,
    ) {
    }
}
