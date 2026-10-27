<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Item;
use App\Entity\Variant;

final readonly class StockLine
{
    public function __construct(
        public Variant $variant,
        public int $quantity,
        public ?Item $item = null,
    ) {
    }
}
