<?php

declare(strict_types=1);

namespace App\Catalogue;

/**
 * Équivalence des EAN : Excel lit parfois un EAN-13 à zéro initial comme un nombre, d'où 12 chiffres.
 * La valeur stockée ne change jamais ; seules les comparaisons passent par la forme GTIN-13 (key).
 */
final class Ean
{
    public static function key(string $ean): string
    {
        $ean = trim($ean);

        return 1 === preg_match('/^\d{12}$/', $ean) ? '0'.$ean : $ean;
    }

    /**
     * Formes stockées possibles du même code.
     *
     * @return list<string>
     */
    public static function candidates(string $ean): array
    {
        $key = self::key($ean);
        if (1 === preg_match('/^0\d{12}$/', $key)) {
            return [$key, substr($key, 1)];
        }

        return [$key];
    }
}
