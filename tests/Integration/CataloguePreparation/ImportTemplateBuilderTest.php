<?php

declare(strict_types=1);

namespace App\Tests\Integration\CataloguePreparation;

use App\CataloguePreparation\ImportTemplateBuilder;
use App\Entity\SupplierImportMapping;
use App\Repository\CategoryRepository;
use App\Tests\Support\ImportFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportTemplateBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ImportTemplateBuilder $builder;
    private SupplierImportMapping $mapping;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $builder = self::getContainer()->get(ImportTemplateBuilder::class);
        \assert($em instanceof EntityManagerInterface && $builder instanceof ImportTemplateBuilder);
        [$this->em, $this->builder] = [$em, $builder];
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $mapping = $this->em->getRepository(SupplierImportMapping::class)->findOneBy(['supplier' => $supplier]);
        \assert($mapping instanceof SupplierImportMapping);
        $this->mapping = $mapping;
    }

    public function testImportSheetHeadersFollowMapping(): void
    {
        $sheet = $this->builder->build($this->mapping)->getSheetByNameOrThrow('Import');

        $headers = $sheet->rangeToArray('A1:N1')[0];
        self::assertSame(array_values(ImportFixtures::BAUER_COLUMNS), $headers);
        self::assertCount(14, $headers);
        self::assertSame('Référence interne', $sheet->getCell('A1')->getValue());
        self::assertSame('Gencode', $sheet->getCell('B1')->getValue());
        self::assertNull($sheet->getCell('O1')->getValue());
    }

    public function testGencodeColumnIsTextWithoutPreCreatedRows(): void
    {
        $sheet = $this->builder->build($this->mapping)->getSheetByNameOrThrow('Import');

        self::assertSame(1, $sheet->getHighestRow(), 'Aucune cellule créée sous les en-têtes.');
        self::assertSame(1, $sheet->getHighestDataRow());
        self::assertSame(NumberFormat::FORMAT_TEXT, self::columnFormat($sheet, 'B'));
        self::assertNotSame(NumberFormat::FORMAT_TEXT, self::columnFormat($sheet, 'A'));
    }

    public function testSavedTemplateKeepsGencodeColumnTextAndNoEmptyRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new Xlsx($this->builder->build($this->mapping)))->save($path);

        try {
            $sheet = IOFactory::load($path)->getSheetByNameOrThrow('Import');
            self::assertSame(1, $sheet->getHighestRow());
            self::assertSame(NumberFormat::FORMAT_TEXT, self::columnFormat($sheet, 'B'));
        } finally {
            @unlink($path);
        }
    }

    private static function columnFormat(Worksheet $sheet, string $column): ?string
    {
        $xfIndex = (int) $sheet->getColumnDimension($column)->getXfIndex();

        return $sheet->getParentOrThrow()->getCellXfByIndex($xfIndex)->getNumberFormat()->getFormatCode();
    }

    public function testCategoriesSheetListsCurrentTree(): void
    {
        $categories = $this->em->getRepository(\App\Entity\Category::class);
        \assert($categories instanceof CategoryRepository);
        $categories->findOrCreatePath(['ZZ TEST', 'NIVEAU DEUX', 'NIVEAU TROIS']);
        $removed = $categories->findOrCreatePath(['ZZ SUPPRIMEE']);
        $this->em->remove($removed);
        $this->em->flush();

        $sheet = $this->builder->build($this->mapping)->getSheetByNameOrThrow('Catégories');
        $rows = $sheet->toArray(null, false, false);

        self::assertContains(['ZZ TEST › NIVEAU DEUX › NIVEAU TROIS', 'ZZ TEST', 'NIVEAU DEUX', 'NIVEAU TROIS'], $rows);
        self::assertContains(['ZZ TEST', 'ZZ TEST', null, null], $rows);
        $paths = array_column($rows, 0);
        self::assertNotContains('ZZ SUPPRIMEE', $paths);
        self::assertSame(['Chemin', 'Catégorie', 'sous-catégorie 1', 'sous-catégorie 2'], $rows[0]);
        $order = array_flip(array_map(static fn (mixed $p): string => \is_string($p) ? $p : '', $paths));
        self::assertLessThan($order['ZZ TEST › NIVEAU DEUX'], $order['ZZ TEST'], 'Chemins triés.');
        self::assertLessThan($order['ZZ TEST › NIVEAU DEUX › NIVEAU TROIS'], $order['ZZ TEST › NIVEAU DEUX'], 'Chemins triés.');
    }

    public function testCorrespondencesSheetMirrorsTable(): void
    {
        $sheet = $this->builder->build($this->mapping)->getSheetByNameOrThrow('Correspondances');
        $rows = $sheet->toArray('', false, false);

        $csv = file(\dirname(__DIR__, 3).'/resources/catalogue-preparation/bauer-correspondances.csv', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        \assert(\is_array($csv));
        self::assertSame($csv, array_map(static fn (array $row): string => implode(';', array_map('strval', $row)), $rows));
    }
}
