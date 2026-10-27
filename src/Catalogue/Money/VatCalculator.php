<?php

declare(strict_types=1);

namespace App\Catalogue\Money;

final class VatCalculator
{
    /**
     * Montant HT en centimes (arrondi au plus proche, demi vers le haut).
     * Le taux est en points de base (20 % = 2000).
     */
    public static function htFromTtc(int $ttcCents, int $rateBasisPoints): int
    {
        return intdiv($ttcCents * 10000 + intdiv(10000 + $rateBasisPoints, 2), 10000 + $rateBasisPoints);
    }
}
