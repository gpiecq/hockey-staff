<?php

declare(strict_types=1);

namespace App\Label;

use App\Entity\Variant;

final readonly class LabelLine
{
    public function __construct(
        public Variant $variant,
        public int $copies,
    ) {
    }

    public function toLabel(): Label
    {
        return new Label($this->designation(), $this->variant->getLabel(), BarcodeChoice::forVariant($this->variant));
    }

    public function designation(): string
    {
        return $this->variant->getProduct()?->getDesignation() ?? '';
    }

    public function code(): string
    {
        return $this->toLabel()->barcode->value;
    }
}
