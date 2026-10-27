<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ImportAnomalyCode;
use App\Import\CatalogueImportAnalyzer;
use App\Import\ImportDiff;
use App\Tests\Support\ImportFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogueImportAnalyzerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CatalogueImportAnalyzer $analyzer;
    private Supplier $supplier;
    private Season $season;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $analyzer = self::getContainer()->get(CatalogueImportAnalyzer::class);
        \assert($em instanceof EntityManagerInterface && $analyzer instanceof CatalogueImportAnalyzer);
        [$this->em, $this->analyzer] = [$em, $analyzer];
        $this->supplier = ImportFixtures::supplierWithMapping($this->em);
        $this->season = ImportFixtures::season($this->em);
    }

    protected function tearDown(): void
    {
        ImportFixtures::cleanup();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'Référence interne' => 'R1', 'Gencode' => '688698508062', 'Désignation' => 'Patin Vapor', 'Taille' => 'SR L',
            'PU Public TTC' => '300', 'PU Club TTC' => '250', 'Catégorie' => 'EQUIPEMENT', 'sous-catégorie 1' => 'JOUEUR',
            'Marque' => 'BAUER',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function analyze(array $rows): ImportDiff
    {
        return $this->analyzer->analyze(ImportFixtures::run($this->em, $this->supplier, $this->season, $rows));
    }

    /** @return list<string> */
    private static function codes(ImportDiff $diff): array
    {
        return array_map(static fn (array $a): string => $a['code'], $diff->anomalies);
    }

    public function testEmptyCatalogueCreatesProductsVariantsAndPrices(): void
    {
        $diff = $this->analyze([
            self::row(['Gencode' => '688698508062', 'Taille' => 'SR L']),
            self::row(['Gencode' => '688698508079', 'Taille' => 'SR M']),
            self::row(['Référence interne' => 'R2', 'Gencode' => '688698508086', 'Taille' => null]),
        ]);

        self::assertCount(2, $diff->newProducts);
        self::assertCount(3, $diff->newVariants);
        self::assertCount(3, $diff->priceChanges);
        self::assertNull($diff->priceChanges[0]['variantId']);
        self::assertSame(['from' => null, 'to' => 25000], $diff->priceChanges[0]['club']);
        self::assertSame(['from' => null, 'to' => 30000], $diff->priceChanges[0]['public']);
        self::assertSame(2, $diff->priceChanges[0]['line']);
        self::assertSame(['R1', 'R2'], array_column($diff->newProducts, 'reference'));
        self::assertSame(['EQUIPEMENT', 'JOUEUR'], $diff->newProducts[0]['categoryPath']);
        self::assertNull($diff->newVariants[0]['productId']);
        self::assertTrue($diff->hasChanges());
        self::assertSame(
            ['newProducts' => 2, 'newVariants' => 3, 'changedVariants' => 0, 'priceChanges' => 3, 'deactivated' => 0, 'reactivated' => 0, 'rejected' => 0, 'anomalies' => 0],
            $diff->summary(),
        );
    }

    public function testMatchByEanReportsDesignationAndPriceChanges(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1', 'Ancien patin');
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 30000, public: 30000);

        $diff = $this->analyze([self::row(['Désignation' => 'Patin Vapor', 'PU Club TTC' => '350', 'PU Public TTC' => '300'])]);

        self::assertSame([], $diff->newProducts);
        self::assertSame([], $diff->newVariants);
        self::assertCount(1, $diff->changedVariants);
        self::assertSame($variant->getId(), $diff->changedVariants[0]['variantId']);
        self::assertSame(['designation' => ['from' => 'Ancien patin', 'to' => 'Patin Vapor']], $diff->changedVariants[0]['changes']);
        self::assertCount(1, $diff->priceChanges);
        self::assertSame($variant->getId(), $diff->priceChanges[0]['variantId']);
        self::assertSame(['from' => 30000, 'to' => 35000], $diff->priceChanges[0]['club']);
        self::assertSame(['from' => 30000, 'to' => 30000], $diff->priceChanges[0]['public']);
    }

    public function testProductLevelChangesAreReportedOnEachMatchedVariant(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1', 'Patin Vapor', 'Warrior', ['EQUIPEMENT', 'GARDIEN']);
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);
        ImportFixtures::variant($this->em, $product, '688698508079', 'SR M', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([
            self::row(['Gencode' => '688698508062', 'Taille' => 'SR L']),
            self::row(['Gencode' => '688698508079', 'Taille' => 'SR M']),
        ]);

        self::assertCount(2, $diff->changedVariants);
        foreach ($diff->changedVariants as $changed) {
            self::assertSame(
                ['brand' => ['from' => 'WARRIOR', 'to' => 'BAUER'], 'category' => ['from' => 'EQUIPEMENT › GARDIEN', 'to' => 'EQUIPEMENT › JOUEUR']],
                $changed['changes'],
            );
        }
    }

    public function testVariantAttributeChangeFoundByEan(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([self::row(['Taille' => 'SR XL', 'Couleur' => 'NOIR'])]);

        self::assertSame(
            ['size' => ['from' => 'SR L', 'to' => 'SR XL'], 'color' => ['from' => null, 'to' => 'NOIR']],
            $diff->changedVariants[0]['changes'],
        );
    }

    public function testMatchWithoutEanByReferenceAndAttributesCreatesNothing(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, null, 'SR L', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([self::row(['Gencode' => null])]);

        self::assertSame([], $diff->newProducts);
        self::assertSame([], $diff->newVariants);
        self::assertSame([], $diff->changedVariants);
        self::assertSame([], $diff->deactivatedVariantIds);
        self::assertFalse($diff->hasChanges());
        self::assertSame([ImportAnomalyCode::EanMissing->value], self::codes($diff));
    }

    public function testNewVariantOfExistingProduct(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([
            self::row(),
            self::row(['Gencode' => '688698508079', 'Taille' => 'SR M']),
        ]);

        self::assertSame([], $diff->newProducts);
        self::assertCount(1, $diff->newVariants);
        self::assertSame($product->getId(), $diff->newVariants[0]['productId']);
        self::assertSame('688698508079', $diff->newVariants[0]['ean']);
        self::assertSame('SR M', $diff->newVariants[0]['size']);
        self::assertSame('R1', $diff->newVariants[0]['reference']);
        self::assertSame(3, $diff->newVariants[0]['line']);
    }

    public function testMissingVariantIsDeactivatedAndInactivePresentOneReactivated(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $absent = ImportFixtures::variant($this->em, $product, '688698508079', 'SR M', season: $this->season, club: 25000, public: 30000);
        $inactive = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', active: false, season: $this->season, club: 25000, public: 30000);
        $alreadyInactiveAbsent = ImportFixtures::variant($this->em, $product, '688698508086', 'SR S', active: false);

        $diff = $this->analyze([self::row()]);

        self::assertSame([$absent->getId()], $diff->deactivatedVariantIds);
        self::assertSame([$inactive->getId()], $diff->reactivatedVariantIds);
        self::assertNotContains($alreadyInactiveAbsent->getId(), $diff->deactivatedVariantIds);
        self::assertTrue($diff->hasChanges());
    }

    public function testOtherSupplierProductsAreNotDeactivated(): void
    {
        $other = ImportFixtures::supplierWithMapping($this->em, 'Warrior');
        $product = ImportFixtures::product($this->em, $other, 'R1');
        ImportFixtures::variant($this->em, $product, '999999999999', 'SR L');

        $diff = $this->analyze([self::row()]);

        self::assertSame([], $diff->deactivatedVariantIds);
        self::assertCount(1, $diff->newProducts, 'La même référence chez un autre fournisseur ne rapproche rien.');
    }

    public function testDuplicateEanInFileRejectsSecondLine(): void
    {
        $diff = $this->analyze([
            self::row(['Taille' => 'SR L']),
            self::row(['Taille' => 'SR M']),
        ]);

        self::assertSame([3], $diff->rejectedLines);
        self::assertContains(ImportAnomalyCode::EanDuplicateInFile->value, self::codes($diff));
        self::assertCount(1, $diff->newVariants);
        self::assertSame(2, $diff->newVariants[0]['line']);
    }

    public function testEanOfAnotherSupplierRejectsLine(): void
    {
        $other = ImportFixtures::supplierWithMapping($this->em, 'Warrior');
        ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $other, 'W1'), '688698508062', 'SR L');

        $diff = $this->analyze([self::row()]);

        self::assertSame([2], $diff->rejectedLines);
        self::assertSame([ImportAnomalyCode::EanOtherSupplier->value], self::codes($diff));
        self::assertSame([], $diff->newVariants);
        self::assertSame([], $diff->newProducts);
    }

    public function testEanOfSoftDeletedVariantIsANewVariant(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $deleted = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L');
        $this->em->remove($deleted);
        $this->em->flush();

        $diff = $this->analyze([self::row()]);

        // Les index uniques sont partiels (deleted_at IS NULL) : un EAN de variante supprimée ne peut pas entrer en collision.
        self::assertSame([], $diff->rejectedLines);
        self::assertSame([], $diff->anomalies);
        self::assertCount(1, $diff->newVariants);
        self::assertSame($product->getId(), $diff->newVariants[0]['productId']);
    }

    public function testEanOfLiveVariantOfSoftDeletedProductIsRejected(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L');
        $this->em->remove($product);
        $this->em->flush();

        $diff = $this->analyze([self::row()]);

        self::assertSame([2], $diff->rejectedLines);
        self::assertSame([ImportAnomalyCode::EanHeldByDeletedProduct->value], self::codes($diff));
        self::assertStringContainsString('R1', $diff->anomalies[0]['message']);
        self::assertSame([], $diff->newVariants);
        self::assertSame([], $diff->newProducts);
    }

    public function testAttributeChangeCollidingWithSiblingMatchKeyIsRejected(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $changing = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);
        ImportFixtures::variant($this->em, $product, '688698508079', 'SR M', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([
            self::row(['Gencode' => '688698508062', 'Taille' => 'SR M']),
            self::row(['Gencode' => '688698508079', 'Taille' => 'SR M']),
        ]);

        self::assertSame([2], $diff->rejectedLines);
        self::assertSame([ImportAnomalyCode::VariantKeyCollision->value], self::codes($diff));
        self::assertSame([], $diff->changedVariants);
        self::assertNotContains($changing->getId(), $diff->deactivatedVariantIds);
        self::assertSame([], $diff->deactivatedVariantIds);
    }

    public function testRejectedRowStillMarksItsVariantPresent(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);
        ImportFixtures::variant($this->em, $product, null, 'SR M', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([
            self::row(['Désignation' => null]),
            self::row(['Gencode' => null, 'Taille' => 'SR M', 'Désignation' => null]),
        ]);

        self::assertSame([2, 3], $diff->rejectedLines);
        self::assertSame([], $diff->deactivatedVariantIds);
        self::assertSame([], $diff->changedVariants);
        self::assertSame([], $diff->newVariants);
    }

    public function testSameReferenceAndAttributesWithDifferentEanIsRejectedAsEanChanged(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, '688698508079', 'SR L', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([self::row(['Gencode' => '688698508062'])]);

        self::assertSame([2], $diff->rejectedLines);
        self::assertSame([ImportAnomalyCode::EanChanged->value], self::codes($diff));
        self::assertSame([], $diff->newVariants);
        self::assertSame([], $diff->deactivatedVariantIds, 'Une ligne rejetée qui identifie la variante la compte comme présente.');
        self::assertNotNull($variant->getId());
    }

    public function testEanOfAnotherReferenceOfSameSupplierIsRejectedAsEanChanged(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([self::row(['Référence interne' => 'R2'])]);

        self::assertSame([2], $diff->rejectedLines);
        self::assertSame([ImportAnomalyCode::EanChanged->value], self::codes($diff));
        self::assertStringContainsString('R1', $diff->anomalies[0]['message']);
        self::assertStringContainsString('R2', $diff->anomalies[0]['message']);
        self::assertSame([], $diff->deactivatedVariantIds);
        self::assertSame([], $diff->newProducts);
    }

    public function testMissingRequiredRowIsRejected(): void
    {
        $diff = $this->analyze([self::row(['Désignation' => null])]);

        self::assertSame([2], $diff->rejectedLines);
        self::assertSame([ImportAnomalyCode::MissingRequired->value], self::codes($diff));
        self::assertSame([], $diff->newProducts);
    }

    public function testNullPriceNeverErasesStoredPrice(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([self::row(['PU Club TTC' => null, 'PU Public TTC' => '350'])]);

        self::assertCount(1, $diff->priceChanges);
        self::assertSame($variant->getId(), $diff->priceChanges[0]['variantId']);
        self::assertSame(['from' => 25000, 'to' => 25000], $diff->priceChanges[0]['club']);
        self::assertSame(['from' => 30000, 'to' => 35000], $diff->priceChanges[0]['public']);
        self::assertSame([ImportAnomalyCode::PriceMissing->value], self::codes($diff));
    }

    public function testNoPriceChangeEntryWhenBothPricesNullOnNewVariantOrNothingChanges(): void
    {
        $diff = $this->analyze([self::row(['PU Club TTC' => null, 'PU Public TTC' => null])]);

        self::assertCount(1, $diff->newVariants);
        self::assertSame([], $diff->priceChanges);
        self::assertSame([ImportAnomalyCode::PriceMissing->value, ImportAnomalyCode::PriceMissing->value], self::codes($diff));
    }

    public function testPricesAreComparedToTheRunSeasonOnly(): void
    {
        $other = ImportFixtures::season($this->em, '2025-2026');
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $other, club: 25000, public: 30000);

        $diff = $this->analyze([self::row()]);

        self::assertCount(1, $diff->priceChanges);
        self::assertSame(['from' => null, 'to' => 25000], $diff->priceChanges[0]['club']);
    }

    public function testUpToDateCatalogueHasNoChangesButAnomaliesAreReported(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);
        ImportFixtures::variant($this->em, $product, null, 'SR M', season: $this->season, club: 25000, public: 30000);

        $diff = $this->analyze([self::row(), self::row(['Gencode' => null, 'Taille' => 'SR M'])]);

        self::assertFalse($diff->hasChanges());
        self::assertSame(1, $diff->summary()['anomalies']);
    }

    public function testConflictingProductFieldsWithinFileAreReported(): void
    {
        $diff = $this->analyze([
            self::row(['Gencode' => '688698508062', 'Taille' => 'SR L']),
            self::row(['Gencode' => '688698508079', 'Taille' => 'SR M', 'Désignation' => 'Autre nom']),
        ]);

        self::assertCount(1, $diff->newProducts);
        self::assertSame('Patin Vapor', $diff->newProducts[0]['designation']);
        self::assertCount(2, $diff->newVariants);
        self::assertSame([ImportAnomalyCode::ProductFieldsConflict->value], self::codes($diff));
        self::assertSame(3, $diff->anomalies[0]['line']);
    }

    public function testTwoEanlessRowsWithSameReferenceAndAttributesRejectSecond(): void
    {
        $diff = $this->analyze([
            self::row(['Gencode' => null]),
            self::row(['Gencode' => null]),
        ]);

        self::assertSame([3], $diff->rejectedLines);
        self::assertContains(ImportAnomalyCode::VariantDuplicateInFile->value, self::codes($diff));
        self::assertCount(1, $diff->newVariants);
    }

    public function testAnalyzeWritesNothingToTheCatalogue(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1', 'Ancien', 'Warrior', ['EQUIPEMENT', 'GARDIEN']);
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);
        $run = ImportFixtures::run($this->em, $this->supplier, $this->season, [
            self::row(['Marque' => 'NOUVELLE MARQUE', 'Catégorie' => 'NOUVEAU', 'PU Club TTC' => '999']),
            self::row(['Référence interne' => 'R9', 'Gencode' => '688698508079', 'Marque' => 'AUTRE', 'Catégorie' => 'AUTRE']),
        ]);
        $before = $this->counts();

        $diff = $this->analyzer->analyze($run);
        $this->em->flush();
        $this->em->clear();

        self::assertTrue($diff->hasChanges());
        self::assertSame($before, $this->counts());
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ([Product::class, Variant::class, SeasonPrice::class, \App\Entity\Brand::class, \App\Entity\Category::class] as $class) {
            $counts[$class] = (int) $this->em->createQuery('SELECT COUNT(e) FROM '.$class.' e')->getSingleScalarResult();
        }

        return $counts;
    }

    public function testDiffRoundTripsThroughArray(): void
    {
        $diff = $this->analyze([self::row(), self::row(['Gencode' => '688698508062'])]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($diff->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        self::assertEquals($diff, ImportDiff::fromArray($decoded));
    }

    #[Group('slow')]
    public function testLargeFileIsAnalyzedQuickly(): void
    {
        // La génération du fichier de test (PhpSpreadsheet) dépasse les 128 Mo du CLI ; l'analyse seule non.
        ini_set('memory_limit', '512M');
        $rows = [];
        for ($i = 0; $i < 2500; ++$i) {
            $rows[] = self::row([
                'Référence interne' => \sprintf('REF%04d', intdiv($i, 4)),
                'Gencode' => \sprintf('%012d', 100000000000 + $i),
                'Taille' => 'T'.($i % 4),
                'Désignation' => 'Produit '.intdiv($i, 4),
            ]);
        }
        // Un cinquième du fichier existe déjà : le rapprochement est exercé.
        for ($p = 0; $p < 120; ++$p) {
            $product = ImportFixtures::product($this->em, $this->supplier, \sprintf('REF%04d', $p), 'Produit '.$p, 'Bauer');
            for ($t = 0; $t < 4; ++$t) {
                ImportFixtures::variant($this->em, $product, \sprintf('%012d', 100000000000 + $p * 4 + $t), 'T'.$t);
            }
        }
        $run = ImportFixtures::run($this->em, $this->supplier, $this->season, $rows);

        $start = microtime(true);
        $diff = $this->analyzer->analyze($run);
        $elapsed = microtime(true) - $start;

        self::assertLessThan(10.0, $elapsed);
        self::assertCount(2500 - 480, $diff->newVariants);
        self::assertSame([], $diff->rejectedLines);
    }
}
