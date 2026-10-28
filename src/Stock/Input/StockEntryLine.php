<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Variant;
use App\Enum\StockCondition;

final readonly class StockEntryLine
{
    /**
     * @param list<string> $serialNumbers
     */
    public function __construct(
        public Variant $variant,
        public int $quantity,
        public ?int $unitCostHt = null,
        public StockCondition $condition = StockCondition::New,
        public array $serialNumbers = [],
    ) {
    }
}
