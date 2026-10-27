<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Entity\Category;
use App\Enum\TrackingMode;

final class TrackingModeResolver
{
    private const SERIALIZED_ROOT = 'EQUIPEMENT';

    public function defaultFor(Category $category): TrackingMode
    {
        return self::SERIALIZED_ROOT === $category->getRoot()->getName()
            ? TrackingMode::Serialized
            : TrackingMode::Quantity;
    }
}
