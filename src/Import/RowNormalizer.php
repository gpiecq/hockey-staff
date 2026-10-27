<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalogue\NameNormalizer;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\SupplierImportMapping;
use App\Entity\Variant;
use App\Enum\ImportAnomalyCode;

final class RowNormalizer
{
    private const array COLOR_WORDS = [
        'NOIR', 'BLANC', 'ROUGE', 'BLEU', 'MARINE', 'GRIS', 'ROSE', 'VERT', 'ORANGE', 'JAUNE', 'VIOLET',
    ];

    /** @param array<string, ?string> $raw valeurs brutes indexées par champ cible (sortie du SpreadsheetReader) */
    public function normalize(int $line, array $raw, SupplierImportMapping $mapping): NormalizedRow
    {
        $anomalies = [];
        $add = static function (ImportAnomalyCode $code, string $message) use (&$anomalies, $line): void {
            $anomalies[] = new ImportAnomaly($line, $code, $message);
        };

        $reference = self::text($raw['reference'] ?? null) ?? '';
        $designation = self::text($raw['designation'] ?? null) ?? '';
        $category1 = self::name($raw['category1'] ?? null);
        $brand = self::name($raw['brand'] ?? null) ?? '';

        foreach ([
            'Référence' => '' === $reference,
            'Désignation' => '' === $designation,
            'Marque' => '' === $brand,
        ] as $label => $isMissing) {
            if ($isMissing) {
                $add(ImportAnomalyCode::MissingRequired, \sprintf('Champ obligatoire manquant : %s.', $label));
            }
        }

        $ean = $this->normalizeEan($raw['ean'] ?? null, $add);
        $publicPrice = $this->normalizePrice($raw['publicPriceTtc'] ?? null, 'public TTC', $add);
        $clubPrice = $this->normalizePrice($raw['clubPriceTtc'] ?? null, 'club TTC', $add);

        $aliases = [];
        foreach ($mapping->getColorAliases() as $from => $to) {
            $aliases[mb_strtoupper(trim($from))] = mb_strtoupper(trim($to));
        }
        $size = self::text($raw['size'] ?? null);
        $color = self::text($raw['color'] ?? null);

        if (null !== $size && null === $color) {
            $upper = mb_strtoupper($size);
            if (\in_array($upper, self::COLOR_WORDS, true) || isset($aliases[$upper])) {
                $color = $upper;
                $size = null;
                $add(ImportAnomalyCode::ColorMovedFromSize, \sprintf('Couleur « %s » trouvée dans la colonne Taille, déplacée vers Couleur.', $color));
            }
        }
        if (null !== $color && isset($aliases[mb_strtoupper($color)])) {
            $original = $color;
            $color = $aliases[mb_strtoupper($color)];
            $add(ImportAnomalyCode::ColorAliased, \sprintf('Couleur « %s » remplacée par « %s ».', $original, $color));
        }

        // Chemin = niveaux non vides ; vide si la catégorie racine manque (catégorie facultative : produit sans catégorie).
        $path = [];
        $levels = [];
        if (null !== $category1) {
            $path[] = $category1;
            $levels['Catégorie'] = $category1;
            $category2 = self::name($raw['category2'] ?? null);
            $category3 = self::name($raw['category3'] ?? null);
            if (null !== $category2) {
                $path[] = $category2;
                $levels['Sous-catégorie 1'] = $category2;
            }
            if (null !== $category3) {
                $path[] = $category3;
                $levels['Sous-catégorie 2'] = $category3;
            }
            if (null === $category2 && null !== $category3) {
                $add(ImportAnomalyCode::CategoryLevelMissing, \sprintf(
                    'Sous-catégorie 1 vide alors que la sous-catégorie 2 est renseignée : catégorie rattachée à « %s ».',
                    implode(' › ', $path),
                ));
            }
        }

        $limits = [
            'Référence' => [$reference, Product::REFERENCE_MAX_LENGTH],
            'Désignation' => [$designation, Product::DESIGNATION_MAX_LENGTH],
            'Taille' => [$size, Variant::ATTRIBUTE_MAX_LENGTH],
            'Coupe' => [self::text($raw['fit'] ?? null), Variant::ATTRIBUTE_MAX_LENGTH],
            'Côté' => [self::text($raw['side'] ?? null), Variant::ATTRIBUTE_MAX_LENGTH],
            'Largeur' => [self::text($raw['width'] ?? null), Variant::ATTRIBUTE_MAX_LENGTH],
            'Couleur' => [$color, Variant::ATTRIBUTE_MAX_LENGTH],
            'Marque' => [$brand, Brand::NAME_MAX_LENGTH],
        ];
        foreach ($levels as $label => $value) {
            $limits[$label] = [$value, Category::NAME_MAX_LENGTH];
        }
        foreach ($limits as $label => [$value, $max]) {
            if (null !== $value && mb_strlen($value) > $max) {
                $add(ImportAnomalyCode::ValueTooLong, \sprintf('Valeur trop longue pour la colonne %s (%d caractères, maximum %d) : ligne ignorée.', $label, mb_strlen($value), $max));
            }
        }

        return new NormalizedRow(
            line: $line,
            reference: $reference,
            ean: $ean,
            designation: $designation,
            size: $size,
            fit: self::text($raw['fit'] ?? null),
            side: self::text($raw['side'] ?? null),
            width: self::text($raw['width'] ?? null),
            color: $color,
            clubPriceTtc: $clubPrice,
            publicPriceTtc: $publicPrice,
            categoryPath: $path,
            brand: $brand,
            anomalies: $anomalies,
        );
    }

    /** @param callable(ImportAnomalyCode, string): void $add */
    private function normalizeEan(?string $value, callable $add): ?string
    {
        $ean = preg_replace('/[\s\x{00A0}]+/u', '', $value ?? '') ?? '';
        if ('' === $ean) {
            $add(ImportAnomalyCode::EanMissing, 'Gencode absent : variante sans EAN.');

            return null;
        }
        if (1 !== preg_match('/^\d+$/', $ean)) {
            $add(ImportAnomalyCode::EanInvalid, \sprintf('Gencode « %s » invalide (chiffres uniquement) : ignoré.', $ean));

            return null;
        }
        $length = \strlen($ean);
        if (11 === $length) {
            $add(ImportAnomalyCode::EanPadded, \sprintf('Gencode « %s » sur 11 chiffres : complété par un 0 initial.', $ean));

            return '0'.$ean;
        }
        if (\in_array($length, [8, 12, 13, 14], true)) {
            return $ean;
        }
        $add(ImportAnomalyCode::EanInvalid, \sprintf('Gencode « %s » invalide (%d chiffres) : ignoré.', $ean, $length));

        return null;
    }

    /** @param callable(ImportAnomalyCode, string): void $add */
    private function normalizePrice(?string $value, string $label, callable $add): ?int
    {
        $price = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value ?? '') ?? '';
        if ('' === $price) {
            $add(ImportAnomalyCode::PriceMissing, \sprintf('Prix %s absent.', $label));

            return null;
        }
        if (1 !== preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $price, $m)) {
            $add(ImportAnomalyCode::PriceInvalid, \sprintf('Prix %s « %s » invalide : ignoré.', $label, $price));

            return null;
        }

        // Au-delà de 9 chiffres entiers, l'entier saturerait (et dépasse de toute façon la colonne).
        if (\strlen(ltrim($m[1], '0')) > 9) {
            $add(ImportAnomalyCode::PriceInvalid, \sprintf('Prix %s « %s » invalide (trop grand) : ignoré.', $label, $price));

            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** Chaîne trimée, vide => null (casse conservée). */
    private static function text(?string $value): ?string
    {
        $value = (string) preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value ?? '');

        return '' === $value ? null : $value;
    }

    /** Nom de référentiel normalisé comme Category/Brand::setName ; vide => null. */
    private static function name(?string $value): ?string
    {
        $name = NameNormalizer::normalize($value ?? '');

        return '' === $name ? null : $name;
    }
}
