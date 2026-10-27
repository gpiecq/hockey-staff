<?php

declare(strict_types=1);

namespace App\Label;

use App\Entity\Item;
use App\Entity\Variant;

/**
 * Une ligne à imprimer : soit une variante, soit une pièce numérotée, en N exemplaires.
 */
final readonly class LabelLine
{
    public function __construct(
        public ?Variant $variant,
        public ?Item $item,
        public int $copies,
    ) {
        if ((null === $variant) === (null === $item)) {
            throw new \LogicException('A label line targets exactly one of a variant or an item.');
        }
    }

    public function toLabel(): Label
    {
        $item = $this->item;
        if (null !== $item) {
            $variant = $item->getVariant();

            return new Label($variant->getProduct()?->getDesignation() ?? '', $variant->getLabel(), BarcodeChoice::forItem($item), $item->getCode());
        }

        $variant = $this->variant ?? throw new \LogicException('Unreachable.');

        return new Label($variant->getProduct()?->getDesignation() ?? '', $variant->getLabel(), BarcodeChoice::forVariant($variant));
    }

    public function designation(): string
    {
        $variant = $this->item?->getVariant() ?? $this->variant;

        return $variant?->getProduct()?->getDesignation() ?? '';
    }

    /** Code-barres imprimé. */
    public function code(): string
    {
        return $this->toLabel()->barcode->value;
    }
}
