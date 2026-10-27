<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Entity\Supplier;
use App\Entity\SupplierImportMapping;
use App\Import\Exception\MissingColumnsException;
use App\Import\Reader\SpreadsheetReader;
use App\Tests\Support\CatalogueSpreadsheetFactory;
use PHPUnit\Framework\TestCase;

final class SpreadsheetReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public static function bauerMapping(): SupplierImportMapping
    {
        return (new SupplierImportMapping(new Supplier()))->setColumns([
            'reference' => 'Référence interne', 'ean' => 'Gencode', 'designation' => 'Désignation',
            'size' => 'Taille', 'fit' => 'Modèle/Fit', 'side' => 'Côté', 'width' => 'Largeur',
            'color' => 'Couleur', 'publicPriceTtc' => 'PU Public TTC', 'clubPriceTtc' => 'PU Club TTC',
            'category1' => 'Catégorie', 'category2' => 'sous-catégorie 1', 'category3' => 'sous-catégorie 2',
            'brand' => 'Marque',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null          $headers
     */
    private function file(array $rows, ?array $headers = null): string
    {
        return $this->files[] = CatalogueSpreadsheetFactory::create($rows, $headers);
    }

    public function testReadsByHeaderNameWithPermutedAndUnknownColumns(): void
    {
        $path = $this->file(
            [['Marque' => 'BAUER', 'Truc inconnu' => 'x', 'Désignation' => 'Patin', 'Catégorie' => 'EQUIPEMENT', 'Référence interne' => 'R1', 'Taille' => 'SR L']],
            ['Marque', 'Truc inconnu', 'Désignation', 'Catégorie', 'Référence interne', 'Taille'],
        );

        $rows = (new SpreadsheetReader())->read($path, self::bauerMapping());

        self::assertSame([2], array_keys($rows));
        self::assertSame('BAUER', $rows[2]['brand']);
        self::assertSame('Patin', $rows[2]['designation']);
        self::assertSame('R1', $rows[2]['reference']);
        self::assertSame('SR L', $rows[2]['size']);
        self::assertNull($rows[2]['ean'], 'colonne non obligatoire absente => null');
    }

    public function testHeaderIsNormalized(): void
    {
        $headers = CatalogueSpreadsheetFactory::BAUER_HEADERS;
        $headers[1] = '  Gencode ';
        $headers[2] = 'DESIGNATION';
        $path = $this->file([['  Gencode ' => '123', 'DESIGNATION' => 'Patin', 'Référence interne' => 'R1']], $headers);

        $rows = (new SpreadsheetReader())->read($path, self::bauerMapping());

        self::assertSame('123', $rows[2]['ean']);
        self::assertSame('Patin', $rows[2]['designation']);
    }

    public function testMissingRequiredHeaderThrows(): void
    {
        $headers = array_values(array_diff(CatalogueSpreadsheetFactory::BAUER_HEADERS, ['Désignation']));
        $path = $this->file([['Référence interne' => 'R1']], $headers);

        try {
            (new SpreadsheetReader())->read($path, self::bauerMapping());
            self::fail('MissingColumnsException attendue');
        } catch (MissingColumnsException $e) {
            self::assertStringContainsString('Désignation', $e->getMessage());
            self::assertSame(['Désignation'], $e->missingHeaders);
        }
    }

    public function testEmptyRowsAreSkippedAndKeysAreExcelLines(): void
    {
        $path = $this->file([
            ['Référence interne' => 'R1'],
            [],
            ['Référence interne' => 'R3'],
        ]);

        $rows = (new SpreadsheetReader())->read($path, self::bauerMapping());

        self::assertSame([2, 4], array_keys($rows));
    }

    public function testNumericCellsAreReadAsPlainStrings(): void
    {
        $path = $this->file([['Référence interne' => 'R1', 'Gencode' => 67235585931, 'PU Club TTC' => 0.5, 'PU Public TTC' => 12.0, 'Taille' => 84]]);

        $row = (new SpreadsheetReader())->read($path, self::bauerMapping())[2];

        self::assertSame('67235585931', $row['ean']);
        self::assertSame('0.5', $row['clubPriceTtc']);
        self::assertSame('12', $row['publicPriceTtc']);
        self::assertSame('84', $row['size']);
    }

    /** @return iterable<string, array{string}> */
    public static function nonSpreadsheetContents(): iterable
    {
        yield 'csv' => ["Référence interne;Gencode\nR1;123\n"];
        yield 'html' => ['<html><body><table><tr><td>Référence interne</td></tr><tr><td>R1</td></tr></table></body></html>'];
        yield 'xml' => ['<?xml version="1.0"?><Workbook></Workbook>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonSpreadsheetContents')]
    public function testNonSpreadsheetFileRenamedXlsxIsRefusedWithAReadableError(string $content): void
    {
        $path = $this->files[] = tempnam(sys_get_temp_dir(), 'fake_').'.xlsx';
        file_put_contents($path, $content);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tableur Excel');

        (new SpreadsheetReader())->read($path, self::bauerMapping());
    }
}
