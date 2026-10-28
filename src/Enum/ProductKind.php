<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductKind: string
{
    case Equipment = 'equipment';
    case Consumable = 'consumable';

    public function label(): string
    {
        return match ($this) {
            self::Equipment => 'Équipement',
            self::Consumable => 'Consommable',
        };
    }
}
