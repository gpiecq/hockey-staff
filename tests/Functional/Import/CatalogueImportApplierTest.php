<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ImportRunStatus;
use App\Enum\ProductKind;
use App\Import\CatalogueImportAnalyzer;
use App\Import\CatalogueImportApplier;
use App\Import\Exception\StaleImportException;
use App\Tests\Support\ImportFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogueImportApplierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CatalogueImportAnalyzer $analyzer;
    private CatalogueImportApplier $applier;
    private Supplier $supplier;
    private Season $season;
    private string $memoryLimit = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $analyzer = self::getContainer()->get(CatalogueImportAnalyzer::class);
        $applier = self::getContainer()->get(CatalogueImportApplier::class);
        \assert($em instanceof EntityManagerInterface && $analyzer instanceof CatalogueImportAnalyzer && $applier instanceof CatalogueImportApplier);
        [$this->em, $this->analyzer, $this->applier] = [$em, $analyzer, $applier];
        $this->supplier = ImportFixtures::supplierWithMapping($this->em);
        $this->season = ImportFixtures::season($this->em);
        // L'import ne crée jamais de catégorie : celles que les lignes de ces tests référencent doivent exister.
        $categories = $this->em->getRepository(Category::class);
        \assert($categories instanceof \App\Repository\CategoryRepository);
        $categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']);
        $categories->findOrCreatePath(['EQUIPEMENT', 'ARBITRE']);
        $categories->findOrCreatePath(['ACCESSOIRES', 'RUBAN', 'TEXTILE']);
        $this->memoryLimit = (string) \ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
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
    private function analyzedRun(array $rows): ImportRun
    {
        $run = ImportFixtures::run($this->em, $this->supplier, $this->season, $rows);
        $run->markAnalyzed($this->analyzer->analyze($run)->toArray());
        $this->em->flush();

        return $run;
    }

    private function variantByEan(string $ean): ?Variant
    {
        return $this->em->getRepository(Variant::class)->findOneBy(['ean' => $ean]);
    }

    private function priceOf(Variant $variant): ?SeasonPrice
    {
        return $this->em->getRepository(SeasonPrice::class)->findOneBy(['variant' => $variant, 'season' => $this->season]);
    }

    public function testApplyCreatesProductsVariantsBrandsAndPrices(): void
    {
        $run = $this->analyzedRun([
            self::row(['Taille' => 'SR L']),
            self::row(['Gencode' => '688698508079', 'Taille' => 'SR M']),
            self::row(['Référence interne' => 'R2', 'Gencode' => '688698508086', 'Taille' => null, 'Catégorie' => 'ACCESSOIRES', 'sous-catégorie 1' => 'RUBAN', 'sous-catégorie 2' => 'TEXTILE', 'Marque' => 'NOUVELLE MARQUE', 'PU Club TTC' => '4,5', 'PU Public TTC' => '6']),
        ]);

        $diff = $this->applier->apply($run);

        self::assertSame(ImportRunStatus::Applied, $run->getStatus());
        self::assertNotNull($run->getAppliedAt());
        self::assertCount(2, $diff->newProducts);
        $r1 = $this->em->getRepository(Product::class)->findOneBy(['supplier' => $this->supplier, 'reference' => 'R1']);
        $r2 = $this->em->getRepository(Product::class)->findOneBy(['supplier' => $this->supplier, 'reference' => 'R2']);
        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertCount(2, $r1->getVariants());
        self::assertSame('BAUER', $r1->getBrand()?->getName());
        self::assertSame('NOUVELLE MARQUE', $r2->getBrand()?->getName());
        self::assertNotNull($this->em->getRepository(Brand::class)->findOneBy(['name' => 'NOUVELLE MARQUE']));
        self::assertSame('ACCESSOIRES › RUBAN › TEXTILE', $r2->getCategory()?->getPath());
        // Type issu de ProductKindResolver::defaultFor.
        self::assertSame(ProductKind::Equipment, $r1->getKind());
        self::assertSame(ProductKind::Consumable, $r2->getKind());
        $variant = $this->variantByEan('688698508062');
        self::assertNotNull($variant);
        self::assertTrue($variant->isActive());
        $price = $this->priceOf($variant);
        self::assertSame([25000, 30000], [$price?->getClubPriceTtc(), $price?->getPublicPriceTtc()]);
        $price2 = $this->priceOf($this->variantByEan('688698508086') ?? throw new \LogicException());
        self::assertSame([450, 600], [$price2?->getClubPriceTtc(), $price2?->getPublicPriceTtc()]);
    }

    public function testApplyReanalyzesInsteadOfTrustingTheStoredDiff(): void
    {
        $run = $this->analyzedRun([self::row()]);
        self::assertCount(1, \App\Import\ImportDiff::fromArray($run->getDiff() ?? [])->newVariants);

        // Entre l'analyse et l'application, la variante est créée à la main (aucun import appliqué).
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);

        $diff = $this->applier->apply($run);

        self::assertSame([], $diff->newVariants);
        self::assertCount(1, $this->em->getRepository(Variant::class)->findBy(['ean' => '688698508062']));
        self::assertCount(1, $this->em->getRepository(Product::class)->findBy(['supplier' => $this->supplier, 'reference' => 'R1']));
    }

    public function testStaleWhenAnotherRunOfTheSameSupplierWasAppliedAfterTheAnalysis(): void
    {
        $run = $this->analyzedRun([self::row()]);
        $other = ImportFixtures::run($this->em, $this->supplier, $this->season, [self::row(['Référence interne' => 'R9', 'Gencode' => '688698508093'])]);
        $other->markAnalyzed([]);
        $other->markApplied([]);
        $this->em->flush();

        try {
            $this->applier->apply($run);
            self::fail('StaleImportException attendue.');
        } catch (StaleImportException $e) {
            self::assertSame('Le catalogue a été modifié depuis l\'analyse, relancez l\'analyse.', $e->getMessage());
        }

        self::assertSame(ImportRunStatus::Analyzed, $run->getStatus());
        self::assertNull($this->variantByEan('688698508062'));
    }

    public function testRunAppliedForAnotherSupplierIsNotStale(): void
    {
        $run = $this->analyzedRun([self::row()]);
        $otherSupplier = ImportFixtures::supplierWithMapping($this->em, 'Warrior');
        $other = new ImportRun($otherSupplier, $this->season, 'x.xlsx', 'x.xlsx');
        $this->em->persist($other);
        $other->markAnalyzed([]);
        $other->markApplied([]);
        $this->em->flush();

        $this->applier->apply($run);

        self::assertNotNull($this->variantByEan('688698508062'));
    }

    public function testAbsentVariantsWithoutStockAreArchivedNeverDeleted(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $kept = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);
        $absent = ImportFixtures::variant($this->em, $product, '688698508079', 'SR M', season: $this->season, club: 25000, public: 30000);

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertTrue($kept->isActive());
        self::assertFalse($absent->isActive());
        self::assertTrue($absent->isArchived());
        self::assertNull($absent->getDeletedAt());
        $this->em->clear();
        $reloaded = $this->em->getRepository(Variant::class)->findOneBy(['ean' => '688698508079']);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
        self::assertTrue($reloaded->isArchived());
    }

    public function testPresentInactiveVariantIsReactivated(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', active: false, season: $this->season, club: 25000, public: 30000);

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertTrue($variant->isActive());
    }

    public function testRejectedLinesWriteNothing(): void
    {
        $this->applier->apply($this->analyzedRun([
            self::row(),
            self::row(['Référence interne' => 'R2', 'Gencode' => '688698508079', 'Désignation' => null]),
            self::row(['Référence interne' => 'R3', 'Gencode' => '688698508062']),
        ]));

        self::assertNotNull($this->variantByEan('688698508062'));
        self::assertNull($this->variantByEan('688698508079'));
        self::assertNull($this->em->getRepository(Product::class)->findOneBy(['supplier' => $this->supplier, 'reference' => 'R2']));
        self::assertNull($this->em->getRepository(Product::class)->findOneBy(['supplier' => $this->supplier, 'reference' => 'R3']));
    }

    public function testChangesAreAppliedWithProductLevelBrandAndCategory(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1', 'Ancien', 'Warrior', ['EQUIPEMENT', 'GARDIEN']);
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);

        $this->applier->apply($this->analyzedRun([
            self::row(['Désignation' => 'Nouveau nom', 'Marque' => 'MARQUE NEUVE', 'Catégorie' => 'EQUIPEMENT', 'sous-catégorie 1' => 'ARBITRE', 'Couleur' => 'BLK']),
        ]));

        self::assertSame('NOUVEAU NOM', strtoupper($product->getDesignation()));
        self::assertSame('MARQUE NEUVE', $product->getBrand()?->getName());
        self::assertSame('EQUIPEMENT › ARBITRE', $product->getCategory()?->getPath());
        self::assertSame('NOIR', $variant->getColor());
        self::assertNotNull($this->em->getRepository(Brand::class)->findOneBy(['name' => 'MARQUE NEUVE']));
    }

    public function testEanSuppliedForAVariantWithoutOneIsSet(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, null, 'SR L', season: $this->season, club: 25000, public: 30000);

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertSame('688698508062', $variant->getEan());
    }

    public function testMissingPriceInFileNeverErasesAndOnlyChangedSidesAreWritten(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);

        $this->applier->apply($this->analyzedRun([self::row(['PU Club TTC' => null, 'PU Public TTC' => '320'])]));

        $price = $this->priceOf($variant);
        self::assertSame([25000, 32000], [$price?->getClubPriceTtc(), $price?->getPublicPriceTtc()]);
    }

    public function testPriceIsCreatedForExistingVariantWithoutSeasonPrice(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L');

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertSame([25000, 30000], [$this->priceOf($variant)?->getClubPriceTtc(), $this->priceOf($variant)?->getPublicPriceTtc()]);
    }

    public function testReimportingTheSameFileChangesNothing(): void
    {
        $rows = [self::row(), self::row(['Gencode' => '688698508079', 'Taille' => 'SR M'])];
        $this->applier->apply($this->analyzedRun($rows));
        $before = $this->updatedAts();

        // Précision à la seconde des horodatages : la seconde analyse doit être postérieure à la première application.
        sleep(1);
        $second = $this->analyzedRun($rows);
        self::assertFalse(\App\Import\ImportDiff::fromArray($second->getDiff() ?? [])->hasChanges());
        $this->applier->apply($second);

        self::assertSame(ImportRunStatus::Applied, $second->getStatus());
        self::assertSame($before, $this->updatedAts());
    }

    /** @return list<array<string, mixed>> */
    private function updatedAts(): array
    {
        return array_merge(
            $this->em->getConnection()->fetchAllAssociative('SELECT id, updated_at FROM product ORDER BY id'),
            $this->em->getConnection()->fetchAllAssociative('SELECT id, updated_at FROM variant ORDER BY id'),
            $this->em->getConnection()->fetchAllAssociative('SELECT id, updated_at FROM season_price ORDER BY id'),
        );
    }

    public function testExceptionMidApplyRollsEverythingBackAndKeepsTheRunAnalyzed(): void
    {
        $run = $this->analyzedRun([
            self::row(),
            self::row(['Référence interne' => 'R2', 'Gencode' => '688698508079']),
            self::row(['Référence interne' => 'R3', 'Gencode' => '688698508086']),
        ]);
        $runId = $run->getId();
        $connection = $this->em->getConnection();
        $categoriesBefore = $connection->fetchOne('SELECT COUNT(*) FROM category');
        $state = new \ArrayObject(['written' => 0]);
        // On échoue au premier flush après lequel
        // les variantes sont déjà écrites en base (dans la transaction).
        $this->em->getEventManager()->addEventListener(Events::postFlush, new class($state, $connection) {
            /** @param \ArrayObject<string, int> $state */
            public function __construct(private \ArrayObject $state, private \Doctrine\DBAL\Connection $connection)
            {
            }

            public function postFlush(): void
            {
                $written = $this->connection->fetchOne('SELECT COUNT(*) FROM variant');
                \assert(is_numeric($written));
                $written = (int) $written;
                if ($written > 0) {
                    $this->state['written'] = $written;
                    throw new \RuntimeException('Échec simulé');
                }
            }
        });

        try {
            $this->applier->apply($run);
            self::fail('RuntimeException attendue.');
        } catch (\RuntimeException $e) {
            self::assertSame('Échec simulé', $e->getMessage());
        }

        self::assertSame(3, $state['written'], 'Les variantes devaient déjà être en base au moment de l\'échec.');
        self::assertEquals($categoriesBefore, $connection->fetchOne('SELECT COUNT(*) FROM category'), 'L\'import ne crée aucune catégorie.');
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM product'));
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM variant'));
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM season_price'));
        self::assertSame('analyzed', $connection->fetchOne('SELECT status FROM import_run WHERE id = ?', [$runId]));
        self::assertNull($connection->fetchOne('SELECT applied_at FROM import_run WHERE id = ?', [$runId]));
    }

    public function testSecondConcurrentApplyOfTheSameRunWritesNothingAndKeepsTheFirstDiff(): void
    {
        $run = $this->analyzedRun([self::row()]);
        $this->applier->apply($run);
        $connection = $this->em->getConnection();
        $diffBefore = $connection->fetchOne('SELECT diff FROM import_run WHERE id = ?', [$run->getId()]);
        $appliedBefore = $connection->fetchOne('SELECT applied_at FROM import_run WHERE id = ?', [$run->getId()]);
        // Le second appel a chargé le run avant la première application : son état en mémoire est encore Analyzed.
        (new \ReflectionProperty(ImportRun::class, 'status'))->setValue($run, ImportRunStatus::Analyzed);
        sleep(1);

        try {
            $this->applier->apply($run);
            self::fail('LogicException attendue.');
        } catch (\LogicException) {
        }

        self::assertSame($diffBefore, $connection->fetchOne('SELECT diff FROM import_run WHERE id = ?', [$run->getId()]));
        self::assertSame($appliedBefore, $connection->fetchOne('SELECT applied_at FROM import_run WHERE id = ?', [$run->getId()]));
        self::assertSame('applied', $connection->fetchOne('SELECT status FROM import_run WHERE id = ?', [$run->getId()]));
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM variant'));
    }

    public function testBrandWithDifferentCaseInFileReusesTheStoredBrand(): void
    {
        ImportFixtures::product($this->em, $this->supplier, 'R0', 'Autre', 'Bauer');

        $this->applier->apply($this->analyzedRun([self::row(['Marque' => ' bauer '])]));

        self::assertCount(1, $this->em->getRepository(Brand::class)->findBy(['name' => 'BAUER']));
        self::assertEquals(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM brand'));
    }

    public function testApplyOnNonAnalyzedRunThrows(): void
    {
        $run = ImportFixtures::run($this->em, $this->supplier, $this->season, [self::row()]);

        $this->expectException(\LogicException::class);
        $this->applier->apply($run);
    }

    public function testCancelMarksCancelledAndDeletesTheFile(): void
    {
        $run = $this->analyzedRun([self::row()]);
        $file = \dirname(__DIR__, 3).'/var/storage/imports/'.$run->getStoredPath();
        self::assertFileExists($file);

        $this->applier->cancel($run);

        self::assertSame(ImportRunStatus::Cancelled, $run->getStatus());
        self::assertFileDoesNotExist($file);
    }

    public function testCancelOnAppliedRunThrows(): void
    {
        $run = $this->analyzedRun([self::row()]);
        $this->applier->apply($run);

        $this->expectException(\LogicException::class);
        $this->applier->cancel($run);
    }

    #[Group('slow')]
    public function testLargeFileApplyStaysWithinWorkerMemory(): void
    {
        // La génération du fichier (PhpSpreadsheet) dépasse 128 Mo ; la limite est restaurée en tearDown.
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
        $run = $this->analyzedRun($rows);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $start = microtime(true);
        $diff = $this->applier->apply($run);
        $elapsed = microtime(true) - $start;
        $peak = memory_get_peak_usage(true);
        fwrite(\STDERR, \sprintf("\n[apply 2500 rows] %.2f s, baseline %.1f MB, peak %.1f MB (+%.1f MB)\n", $elapsed, $baseline / 1048576, $peak / 1048576, ($peak - $baseline) / 1048576));

        self::assertCount(2500, $diff->newVariants);
        self::assertEquals(2500, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM variant'));
        // En environnement de test (debug), le profileur Doctrine retient chaque requête avec sa trace (~+94 Mo) ;
        // sans debug (worker de production) la croissance mesurée est d'environ +10 Mo. Garde-fou : < 128 Mo de croissance.
        self::assertLessThan(128 * 1048576, $peak - $baseline);
    }
}
