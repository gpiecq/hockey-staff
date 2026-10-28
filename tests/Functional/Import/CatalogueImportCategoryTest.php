<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\Category;
use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ImportAnomalyCode;
use App\Enum\ProductKind;
use App\Import\CatalogueImportAnalyzer;
use App\Import\CatalogueImportApplier;
use App\Import\ImportDiff;
use App\Repository\CategoryRepository;
use App\Tests\Support\ImportFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogueImportCategoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CatalogueImportAnalyzer $analyzer;
    private CatalogueImportApplier $applier;
    private CategoryRepository $categories;
    private Supplier $supplier;
    private Season $season;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $analyzer = self::getContainer()->get(CatalogueImportAnalyzer::class);
        $applier = self::getContainer()->get(CatalogueImportApplier::class);
        $categories = self::getContainer()->get(CategoryRepository::class);
        \assert($em instanceof EntityManagerInterface && $analyzer instanceof CatalogueImportAnalyzer && $applier instanceof CatalogueImportApplier && $categories instanceof CategoryRepository);
        [$this->em, $this->analyzer, $this->applier, $this->categories] = [$em, $analyzer, $applier, $categories];
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
            'PU Public TTC' => '300', 'PU Club TTC' => '250', 'Catégorie' => null, 'sous-catégorie 1' => null,
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

    /** @param list<array<string, mixed>> $rows */
    private function analyze(array $rows): ImportDiff
    {
        return $this->analyzer->analyze(ImportFixtures::run($this->em, $this->supplier, $this->season, $rows));
    }

    /** @return list<string> */
    private static function codes(ImportDiff $diff): array
    {
        return array_column($diff->anomalies, 'code');
    }

    private function product(string $reference): ?Product
    {
        $this->em->clear();

        return $this->em->getRepository(Product::class)->findOneBy(['supplier' => $this->supplier->getId(), 'reference' => $reference]);
    }

    public function testEmptyCategoryCreatesUncategorizedProduct(): void
    {
        $run = $this->analyzedRun([self::row()]);
        $diff = ImportDiff::fromArray($run->getDiff() ?? []);

        self::assertSame([], self::codes($diff));
        self::assertSame([], $diff->rejectedLines);
        self::assertSame([], $diff->newProducts[0]['categoryPath']);

        $this->applier->apply($run);

        $product = $this->product('R1');
        self::assertNotNull($product);
        self::assertNull($product->getCategory());
        self::assertSame(ProductKind::Consumable, $product->getKind());
    }

    public function testUnknownCategoryIsWarningAndNotCreated(): void
    {
        $run = $this->analyzedRun([self::row(['Catégorie' => 'INCONNUE', 'sous-catégorie 1' => 'X'])]);
        $diff = ImportDiff::fromArray($run->getDiff() ?? []);

        self::assertSame([ImportAnomalyCode::CategoryUnknown->value], self::codes($diff));
        self::assertSame('Catégorie « INCONNUE › X » inconnue : produit importé sans catégorie.', $diff->anomalies[0]['message']);
        self::assertSame([], $diff->rejectedLines);
        self::assertTrue($diff->hasChanges());
        self::assertSame([], $diff->newProducts[0]['categoryPath']);

        $this->applier->apply($run);

        self::assertNull($this->em->getRepository(Category::class)->findOneBy(['name' => 'INCONNUE']));
        self::assertNull($this->em->getRepository(Category::class)->findOneBy(['name' => 'X']));
        self::assertNull($this->product('R1')?->getCategory());
    }

    public function testEmptyCategoryKeepsExistingCategory(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1', 'Patin Vapor', 'Bauer', ['EQUIPEMENT', 'JOUEUR']);
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L');
        $run = $this->analyzedRun([self::row()]);
        $diff = ImportDiff::fromArray($run->getDiff() ?? []);

        foreach ($diff->changedVariants as $changed) {
            self::assertArrayNotHasKey('category', $changed['changes']);
        }

        $this->applier->apply($run);

        self::assertSame('EQUIPEMENT › JOUEUR', $this->product('R1')?->getCategory()?->getPath());
    }

    public function testFirstCategorizationByImportAppliesDefaultProductKind(): void
    {
        $this->categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR', 'PATIN']);
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $product->setCategory(null)->setKind(ProductKind::Consumable);
        $this->em->flush();
        ImportFixtures::variant($this->em, $product, '688698508062', 'SR L');

        $run = $this->analyzedRun([self::row(['Catégorie' => 'EQUIPEMENT', 'sous-catégorie 1' => 'JOUEUR', 'sous-catégorie 2' => 'PATIN'])]);
        $this->applier->apply($run);

        $product = $this->product('R1');
        self::assertNotNull($product);
        self::assertSame('EQUIPEMENT › JOUEUR › PATIN', $product->getCategory()?->getPath());
        self::assertSame(ProductKind::Equipment, $product->getKind());
    }

    public function testCategoryMatchIsNormalized(): void
    {
        $this->categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR', 'PATIN']);

        $run = $this->analyzedRun([self::row(['Catégorie' => ' equipement ', 'sous-catégorie 1' => 'Joueur', 'sous-catégorie 2' => 'patin '])]);
        $diff = ImportDiff::fromArray($run->getDiff() ?? []);

        self::assertNotContains(ImportAnomalyCode::CategoryUnknown->value, self::codes($diff));
        self::assertSame(['EQUIPEMENT', 'JOUEUR', 'PATIN'], $diff->newProducts[0]['categoryPath']);

        $this->applier->apply($run);

        self::assertSame('EQUIPEMENT › JOUEUR › PATIN', $this->product('R1')?->getCategory()?->getPath());
    }

    public function testApplyWithCategoryDeletedSinceAnalysisLeavesProductUncategorized(): void
    {
        $category = $this->categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']);
        $run = $this->analyzedRun([self::row(['Catégorie' => 'EQUIPEMENT', 'sous-catégorie 1' => 'JOUEUR'])]);
        self::assertSame(['EQUIPEMENT', 'JOUEUR'], ImportDiff::fromArray($run->getDiff() ?? [])->newProducts[0]['categoryPath']);

        $this->em->remove($category);
        $this->em->flush();
        $this->em->clear();
        $run = $this->em->getRepository(ImportRun::class)->find($run->getId()) ?? throw new \LogicException();

        $this->applier->apply($run);

        $product = $this->product('R1');
        self::assertNotNull($product);
        self::assertNull($product->getCategory());
    }

    public function testEmptyPriceKeepsStoredPrice(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 10000, public: 15000);

        $diff = $this->analyze([self::row(['PU Club TTC' => null, 'PU Public TTC' => null, 'Catégorie' => 'EQUIPEMENT', 'sous-catégorie 1' => 'JOUEUR'])]);

        self::assertSame([], $diff->priceChanges);
        $run = $this->analyzedRun([self::row(['PU Club TTC' => null, 'PU Public TTC' => null])]);
        $this->applier->apply($run);
        $this->em->clear();
        $price = $this->em->getRepository(SeasonPrice::class)->findOneBy(['variant' => $variant->getId(), 'season' => $this->season->getId()]);
        self::assertSame([10000, 15000], [$price?->getClubPriceTtc(), $price?->getPublicPriceTtc()]);
    }

    public function testEmptyPriceOnNewVariantCreatesNoSeasonPrice(): void
    {
        $run = $this->analyzedRun([self::row(['PU Club TTC' => null, 'PU Public TTC' => null])]);
        $this->applier->apply($run);

        $this->em->clear();
        $variant = $this->em->getRepository(Variant::class)->findOneBy(['ean' => '688698508062']);
        self::assertNotNull($variant);
        self::assertCount(0, $this->em->getRepository(SeasonPrice::class)->findBy(['variant' => $variant->getId()]));
    }

    public function testReferenceOnlyLineIsStableOnReimport(): void
    {
        $line = self::row(['Gencode' => null, 'Taille' => null, 'PU Club TTC' => null, 'PU Public TTC' => null]);

        $first = $this->analyzedRun([$line]);
        $this->applier->apply($first);

        $second = $this->analyzedRun([$line]);
        $diff = ImportDiff::fromArray($second->getDiff() ?? []);

        self::assertSame([], $diff->newVariants);
        self::assertNotContains(ImportAnomalyCode::VariantKeyCollision->value, self::codes($diff));
        self::assertSame(1, (int) $this->em->createQuery('SELECT COUNT(v) FROM '.Variant::class.' v')->getSingleScalarResult());
    }
}
