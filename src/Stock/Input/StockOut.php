<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;
use App\Enum\StockOutReason;

final readonly class StockOut
{
    /**
     * @param list<StockLine> $lines
     */
    public function __construct(
        public Location $location,
        public StockOutReason $reason,
        public array $lines,
        public ?string $comment = null,
    ) {
    }
}
