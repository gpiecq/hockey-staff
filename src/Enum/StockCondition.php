<?php

declare(strict_types=1);

namespace App\Enum;

enum StockCondition: string
{
    case New = 'new';
    case Good = 'good';
    case Worn = 'worn';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Neuf',
            self::Good => 'Bon',
            self::Worn => 'Usé',
            self::Damaged => 'Abîmé',
        };
    }
}
