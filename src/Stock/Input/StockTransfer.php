<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;

final readonly class StockTransfer
{
    /**
     * @param list<StockLine> $lines
     */
    public function __construct(
        public Location $from,
        public Location $to,
        public array $lines,
        public ?string $comment = null,
    ) {
    }
}
