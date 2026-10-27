<?php

declare(strict_types=1);

namespace App\Import\Reader;

use App\Entity\SupplierImportMapping;
use App\Import\Exception\MissingColumnsException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Lecture seule de la première feuille d'un classeur : colonnes repérées par en-tête (ligne 1),
 * toutes les valeurs sont lues en chaîne.
 */
final class SpreadsheetReader
{
    /**
     * @return array<int, array<string, ?string>> clé = n° de ligne Excel, valeurs indexées par champ cible
     *
     * @throws MissingColumnsException
     */
    public function read(string $path, SupplierImportMapping $mapping): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $spreadsheet = $reader->load($path);

        try {
            /** @var array<int, array<string, mixed>> $cells */
            $cells = $spreadsheet->getSheet(0)->toArray(null, true, false, true);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $headerRow = $cells[1] ?? [];
        unset($cells[1]);

        // en-tête normalisé => lettre de colonne (première occurrence)
        $columnByHeader = [];
        foreach ($headerRow as $letter => $value) {
            $key = self::normalizeHeader(self::stringify($value) ?? '');
            if ('' !== $key && !isset($columnByHeader[$key])) {
                $columnByHeader[$key] = $letter;
            }
        }

        $letters = [];
        foreach ($mapping->getColumns() as $field => $header) {
            $letters[$field] = $columnByHeader[self::normalizeHeader($header)] ?? null;
        }

        $missing = [];
        foreach (SupplierImportMapping::REQUIRED_FIELDS as $field) {
            if (null === ($letters[$field] ?? null)) {
                $missing[] = $mapping->getColumns()[$field] ?? $field;
            }
        }
        if ([] !== $missing) {
            throw new MissingColumnsException($missing);
        }

        $rows = [];
        foreach ($cells as $line => $rowCells) {
            $row = [];
            $empty = true;
            foreach (SupplierImportMapping::FIELDS as $field) {
                $letter = $letters[$field] ?? null;
                $value = null === $letter ? null : self::stringify($rowCells[$letter] ?? null);
                $row[$field] = $value;
            }
            // une ligne n'est vide que si TOUTES ses cellules le sont (colonnes inconnues comprises)
            foreach ($rowCells as $value) {
                if ('' !== trim(self::stringify($value) ?? '')) {
                    $empty = false;
                    break;
                }
            }
            if (!$empty) {
                $rows[(int) $line] = $row;
            }
        }

        return $rows;
    }

    public static function normalizeHeader(string $header): string
    {
        $header = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $header));
        $header = mb_strtolower($header);
        $ascii = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $header);

        return false === $ascii ? $header : $ascii;
    }

    /** Valeur de cellule en chaîne, sans notation scientifique pour les nombres. */
    private static function stringify(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            if (floor($value) === $value && abs($value) < 1.0e15) {
                return \sprintf('%.0f', $value);
            }

            return rtrim(rtrim(\sprintf('%.10F', $value), '0'), '.');
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        return \is_string($value) ? $value : null;
    }
}
