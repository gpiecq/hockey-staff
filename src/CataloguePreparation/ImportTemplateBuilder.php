<?php

declare(strict_types=1);

namespace App\CataloguePreparation;

use App\Entity\Category;
use App\Entity\SupplierImportMapping;
use App\Repository\CategoryRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Template d'import à remplir par une IA : onglet « Import » (en-têtes du mapping fournisseur), onglet « Catégories »
 * (référentiel actuel) et onglet « Correspondances » (table fixe onglet + section → catégorie).
 */
final readonly class ImportTemplateBuilder
{
    public const string CORRESPONDENCES_FILE = 'bauer-correspondances.csv';

    /** Lignes de saisie préformatées en texte dans la colonne Gencode. */
    public function __construct(
        private CategoryRepository $categories,
        #[Autowire('%kernel.project_dir%/resources/catalogue-preparation')]
        private string $resourcesDir,
    ) {
    }

    public function build(SupplierImportMapping $mapping): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $columns = $mapping->getColumns();

        $import = $spreadsheet->getActiveSheet()->setTitle('Import');
        $headers = [];
        foreach (SupplierImportMapping::FIELDS as $field) {
            if (isset($columns[$field])) {
                $headers[$field] = $columns[$field];
            }
        }
        $import->fromArray(array_values($headers));
        $eanIndex = array_search('ean', array_keys($headers), true);
        if (false !== $eanIndex) {
            $letter = Coordinate::stringFromColumnIndex($eanIndex + 1);
            // Style de colonne (<col style>) : aucune cellule créée, la feuille reste à 1 ligne pour les scripts.
            $import->getStyle($letter.':'.$letter)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
        $this->decorate($import, \count($headers));

        $tree = $spreadsheet->createSheet()->setTitle('Catégories');
        $rows = [['Chemin', $columns['category1'] ?? 'Catégorie', $columns['category2'] ?? 'Sous-catégorie 1', $columns['category3'] ?? 'Sous-catégorie 2']];
        /** @var list<Category> $categories */
        $categories = $this->categories->createTreeQueryBuilder()->getQuery()->getResult();
        foreach ($categories as $category) {
            $levels = explode(CorrespondenceTable::PATH_SEPARATOR, $category->getPath());
            $rows[] = [$category->getPath(), $levels[0], $levels[1] ?? null, $levels[2] ?? null];
        }
        $tree->fromArray($rows, null, 'A1', true);
        $this->decorate($tree, 4);

        $correspondences = $spreadsheet->createSheet()->setTitle('Correspondances');
        $table = CorrespondenceTable::fromCsv($this->correspondencesPath())->toTable();
        // Valeurs écrites en texte : aucun libellé ne doit être interprété (nombre, formule).
        foreach ($table as $r => $row) {
            foreach ($row as $c => $value) {
                if ('' !== $value) {
                    $correspondences->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_STRING);
                }
            }
        }
        $this->decorate($correspondences, \count(CorrespondenceTable::HEADER));

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function correspondencesPath(): string
    {
        return $this->resourcesDir.'/'.self::CORRESPONDENCES_FILE;
    }

    private function decorate(Worksheet $sheet, int $columnCount): void
    {
        $last = Coordinate::stringFromColumnIndex(max(1, $columnCount));
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        for ($i = 1; $i <= $columnCount; ++$i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }
}
