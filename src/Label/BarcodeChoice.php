<?php

declare(strict_types=1);

namespace App\Label;

use App\Catalogue\Ean;
use App\Entity\Variant;

final class BarcodeChoice
{
    public static function forVariant(Variant $variant): LabelBarcode
    {
        $ean = trim($variant->getEan() ?? '');

        if (Ean::hasValidCheckDigit($ean)) {
            return new LabelBarcode(12 === \strlen($ean) ? Symbology::UpcA : Symbology::Ean13, $ean);
        }

        $code = $variant->getCode() ?? throw new \LogicException('Variant has no internal code yet.');

        return new LabelBarcode(Symbology::Code128, $code);
    }
}
