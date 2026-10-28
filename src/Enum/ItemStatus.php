<?php

declare(strict_types=1);

namespace App\Enum;

enum ItemStatus: string
{
    case InStock = 'in_stock';
    case Removed = 'removed';
    case Loaned = 'loaned';
    case Gifted = 'gifted';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'En stock',
            self::Removed => 'Sortie',
            self::Loaned => 'Prêtée',
            self::Gifted => 'Donnée',
            self::Sold => 'Vendue',
        };
    }
}
