<?php

declare(strict_types=1);

namespace App\Enum;

enum StockOutReason: string
{
    case Lost = 'lost';
    case Broken = 'broken';
    case Scrapped = 'scrapped';
    case EntryError = 'entry_error';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lost => 'Perte',
            self::Broken => 'Casse',
            self::Scrapped => 'Rebut',
            self::EntryError => 'Erreur de saisie',
            self::Other => 'Autre',
        };
    }
}
