<?php

declare(strict_types=1);

namespace App\Tests\Unit\CataloguePreparation;

use App\CataloguePreparation\CorrespondenceTable;
use App\CataloguePreparation\InvalidCorrespondenceTableException;
use PHPUnit\Framework\TestCase;

final class CorrespondenceTableTest extends TestCase
{
    private const string HEADER = 'onglet;section;categorie1;categorie2;categorie3;marque;candidates';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testParsesUnambiguousAndAmbiguousRows(): void
    {
        $path = $this->csv([
            self::HEADER,
            'PATIN;PATIN SENIOR;EQUIPEMENT;JOUEUR;PATIN;;',
            'CULOTTE;SUR CULOTTE;;;;;EQUIPEMENT › JOUEUR › CULOTTE | ACCESSOIRE PATIN',
            'TRAINING;GREEN BISCUIT;TRAINING;;;GREEN BISCUIT;',
        ]);

        $rows = CorrespondenceTable::fromCsv($path)->rows();

        self::assertSame([
            ['onglet' => 'PATIN', 'section' => 'PATIN SENIOR', 'path' => ['EQUIPEMENT', 'JOUEUR', 'PATIN'], 'brand' => null, 'candidates' => []],
            ['onglet' => 'CULOTTE', 'section' => 'SUR CULOTTE', 'path' => [], 'brand' => null, 'candidates' => [['EQUIPEMENT', 'JOUEUR', 'CULOTTE'], ['ACCESSOIRE PATIN']]],
            ['onglet' => 'TRAINING', 'section' => 'GREEN BISCUIT', 'path' => ['TRAINING'], 'brand' => 'GREEN BISCUIT', 'candidates' => []],
        ], $rows);
    }

    public function testRejectsInvalidHeader(): void
    {
        $path = $this->csv(['onglet;section;categorie;marque', 'PATIN;PATIN SENIOR;EQUIPEMENT;']);

        $this->expectException(InvalidCorrespondenceTableException::class);
        $this->expectExceptionMessage('En-tête');

        CorrespondenceTable::fromCsv($path);
    }

    public function testShippedBauerTableIsValid(): void
    {
        $rows = CorrespondenceTable::fromCsv(\dirname(__DIR__, 3).'/resources/catalogue-preparation/bauer-correspondances.csv')->rows();

        self::assertCount(99, $rows);
        self::assertCount(5, array_filter($rows, static fn (array $row): bool => [] !== $row['candidates']));
    }

    /** @param list<string> $lines */
    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'corr');
        \assert(false !== $path);
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->files[] = $path;

        return $path;
    }
}
