<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CatalogueSpreadsheetFactory
{
    public const array BAUER_HEADERS = [
        'Référence interne', 'Gencode', 'Désignation', 'Taille', 'Modèle/Fit', 'Côté', 'Largeur', 'Couleur',
        'PU Public TTC', 'PU Club TTC', 'Catégorie', 'sous-catégorie 1', 'sous-catégorie 2', 'Marque',
    ];

    /**
     * Écrit un .xlsx temporaire. Chaque ligne est indexée par en-tête ; les entiers/flottants deviennent des
     * cellules numériques, les chaînes des cellules texte ; en-tête absent de la ligne => cellule vide.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null          $headers
     */
    public static function create(array $rows, ?array $headers = null): string
    {
        $headers ??= self::BAUER_HEADERS;
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalogue');

        foreach ($headers as $c => $header) {
            $sheet->setCellValueExplicit([$c + 1, 1], $header, DataType::TYPE_STRING);
        }
        foreach ($rows as $r => $row) {
            foreach ($headers as $c => $header) {
                $value = $row[$header] ?? null;
                if (null === $value) {
                    continue;
                }
                $type = \is_int($value) || \is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING;
                $sheet->setCellValueExplicit([$c + 1, $r + 2], $value, $type);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'catalogue_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
