<?php

declare(strict_types=1);

namespace App\Enum;

enum ItemStatus: string
{
    case InStock = 'in_stock';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'En stock',
            self::Removed => 'Sortie',
        };
    }
}
