<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;
use App\Entity\Player;

/**
 * Vente d'occasion d'une ou plusieurs pièces, toutes en stock dans le même lieu.
 */
final readonly class StockSale
{
    /**
     * @param list<StockSaleLine> $lines
     */
    public function __construct(
        public Location $location,
        public string $buyer,
        public array $lines,
        public ?Player $player = null,
        public ?string $comment = null,
    ) {
    }
}
