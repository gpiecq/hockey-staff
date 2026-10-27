<?php

declare(strict_types=1);

namespace App\Label;

enum LabelFormat: string
{
    case Sheet = 'a4';
    case Roll = 'rouleau';

    public function label(): string
    {
        return match ($this) {
            self::Sheet => 'Planche A4 (21 étiquettes)',
            self::Roll => 'Rouleau 62 × 29 mm',
        };
    }
}
