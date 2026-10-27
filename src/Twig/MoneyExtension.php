<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

final class MoneyExtension
{
    /** Montant en centimes → « 12,50 € » (espaces insécables). */
    #[AsTwigFilter('euros')]
    public function euros(?int $cents): string
    {
        if (null === $cents) {
            return '';
        }

        return number_format($cents / 100, 2, ',', "\u{00A0}")."\u{00A0}€";
    }
}
