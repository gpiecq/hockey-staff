<?php

declare(strict_types=1);

namespace App\Label;

final readonly class LabelBarcode
{
    public function __construct(
        public Symbology $symbology,
        public string $value,
    ) {
    }
}
