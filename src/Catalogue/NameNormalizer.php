<?php

declare(strict_types=1);

namespace App\Catalogue;

/**
 * Normalisation des noms de référentiel : trim, espaces multiples réduits, majuscules.
 */
final class NameNormalizer
{
    public static function normalize(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
