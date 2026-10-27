<?php

declare(strict_types=1);

namespace App\Enum;

enum StockMovementType: string
{
    case PurchaseIn = 'purchase_in';
    case DonationIn = 'donation_in';
    case Out = 'out';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseIn => 'Achat',
            self::DonationIn => 'Dotation',
            self::Out => 'Sortie',
            self::Transfer => 'Transfert',
        };
    }
}
