<?php

declare(strict_types=1);

namespace App\Enum;

enum ImportAnomalyCode: string
{
    case EanMissing = 'ean_missing';
    case EanPadded = 'ean_padded';
    case EanInvalid = 'ean_invalid';
    case EanDuplicateInFile = 'ean_duplicate_in_file';
    case EanOtherSupplier = 'ean_other_supplier';
    case EanChanged = 'ean_changed';
    case PriceMissing = 'price_missing';
    case PriceInvalid = 'price_invalid';
    case ColorMovedFromSize = 'color_moved_from_size';
    case ColorAliased = 'color_aliased';
    case MissingRequired = 'missing_required';
}
