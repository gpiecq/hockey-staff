<?php

declare(strict_types=1);

namespace App\Stock\Input;

use App\Entity\Location;
use App\Enum\StockMovementType;

final readonly class StockEntry
{
    /**
     * @param list<StockEntryLine> $lines
     */
    public function __construct(
        public StockMovementType $type,
        public Location $location,
        public array $lines,
        public ?string $documentReference = null,
        public ?string $comment = null,
    ) {
        if (!\in_array($type, [StockMovementType::PurchaseIn, StockMovementType::DonationIn], true)) {
            throw new \InvalidArgumentException('Une entrée est un achat ou une dotation.');
        }
    }
}
