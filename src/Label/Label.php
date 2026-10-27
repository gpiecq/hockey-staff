<?php

declare(strict_types=1);

namespace App\Label;

final readonly class Label
{
    public function __construct(
        public string $designation,
        public string $attributes,
        public LabelBarcode $barcode,
        public ?string $itemCode = null,
    ) {
    }
}
