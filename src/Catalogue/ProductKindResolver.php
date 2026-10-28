<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Entity\Category;
use App\Enum\ProductKind;

final class ProductKindResolver
{
    private const EQUIPMENT_ROOT = 'EQUIPEMENT';

    public function defaultFor(Category $category): ProductKind
    {
        return self::EQUIPMENT_ROOT === $category->getRoot()->getName()
            ? ProductKind::Equipment
            : ProductKind::Consumable;
    }
}
