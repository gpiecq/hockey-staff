<?php

declare(strict_types=1);

namespace App\Enum;

enum TrackingMode: string
{
    case Serialized = 'serialized';
    case Quantity = 'quantity';

    public function label(): string
    {
        return match ($this) {
            self::Serialized => 'Pièce par pièce',
            self::Quantity => 'Quantité',
        };
    }
}
