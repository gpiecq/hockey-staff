<?php

declare(strict_types=1);

namespace App\Enum;

enum StockMovementType: string
{
    case PurchaseIn = 'purchase_in';
    case DonationIn = 'donation_in';
    case Out = 'out';
    case Transfer = 'transfer';
    case LoanOut = 'loan_out';
    case LoanReturn = 'loan_return';
    case GiftOut = 'gift_out';
    case SaleOut = 'sale_out';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseIn => 'Achat',
            self::DonationIn => 'Dotation',
            self::Out => 'Sortie',
            self::Transfer => 'Transfert',
            self::LoanOut => 'Prêt',
            self::LoanReturn => 'Retour de prêt',
            self::GiftOut => 'Don',
            self::SaleOut => 'Vente',
        };
    }
}
