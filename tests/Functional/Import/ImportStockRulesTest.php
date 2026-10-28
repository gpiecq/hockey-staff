<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Season;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ImportAnomalyCode;
use App\Enum\ItemCondition;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Import\CatalogueImportAnalyzer;
use App\Import\CatalogueImportApplier;
use App\Import\ImportDiff;
use App\Repository\CategoryRepository;
use App\Stock\Input\StockLoan;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ImportStockRulesTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private CatalogueImportAnalyzer $analyzer;
    private CatalogueImportApplier $applier;
    private StockMovementRecorder $recorder;
    private Supplier $supplier;
    private Season $season;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $analyzer = $container->get(CatalogueImportAnalyzer::class);
        $applier = $container->get(CatalogueImportApplier::class);
        $recorder = $container->get(StockMovementRecorder::class);
        \assert($em instanceof EntityManagerInterface && $analyzer instanceof CatalogueImportAnalyzer);
        \assert($applier instanceof CatalogueImportApplier && $recorder instanceof StockMovementRecorder);
        [$this->em, $this->analyzer, $this->applier, $this->recorder] = [$em, $analyzer, $applier, $recorder];
        $this->supplier = ImportFixtures::supplierWithMapping($this->em);
        $this->season = ImportFixtures::season($this->em);
        $categories = $this->em->getRepository(Category::class);
        \assert($categories instanceof CategoryRepository);
        $categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']);
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
    private function analyzedRun(array $rows): \App\Entity\ImportRun
    {
        $run = ImportFixtures::run($this->em, $this->supplier, $this->season, $rows);
        $run->markAnalyzed($this->analyzer->analyze($run)->toArray());
        $this->em->flush();

        return $run;
    }

    private function absentVariant(string $ean = '688698508079'): Variant
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');

        return ImportFixtures::variant($this->em, $product, $ean, 'SR M');
    }

    private function stockIn(Variant $variant): void
    {
        StockScenario::receive($this->recorder, $variant, StockFixtures::location($this->em), 1, 5000);
    }

    public function testAbsentVariantWithoutStockIsArchived(): void
    {
        $absent = $this->absentVariant();

        $diff = $this->analyzer->analyze(ImportFixtures::run($this->em, $this->supplier, $this->season, [self::row()]));
        self::assertSame([$absent->getId()], $diff->archivedVariantIds);
        self::assertSame([], $diff->deactivatedVariantIds);

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertTrue($absent->isArchived());
        self::assertFalse($absent->isActive());
    }

    public function testAbsentVariantInStockIsOnlyDeactivated(): void
    {
        $absent = $this->absentVariant();
        $this->stockIn($absent);

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertFalse($absent->isActive());
        self::assertFalse($absent->isArchived());
    }

    public function testAbsentVariantWhoseOnlyItemIsLoanedIsNotArchived(): void
    {
        $absent = $this->absentVariant();
        $absent->getProduct()?->setTrackingMode(TrackingMode::Serialized);
        $this->em->flush();
        $location = StockFixtures::location($this->em);
        StockScenario::receive($this->recorder, $absent, $location, 1, 5000);
        $this->recorder->recordLoan(new StockLoan($location, PlayerFixtures::player($this->em), $absent, ItemCondition::New));

        $diff = $this->analyzer->analyze(ImportFixtures::run($this->em, $this->supplier, $this->season, [self::row()]));
        self::assertSame([], $diff->archivedVariantIds);
        self::assertSame([$absent->getId()], $diff->deactivatedVariantIds);

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertFalse($absent->isActive());
        self::assertFalse($absent->isArchived());
    }

    public function testInactiveNonArchivedVariantWithoutStockIsArchivedByCatchUp(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $old = ImportFixtures::variant($this->em, $product, '688698508079', 'SR M', active: false);

        $diff = $this->analyzer->analyze(ImportFixtures::run($this->em, $this->supplier, $this->season, [self::row()]));

        self::assertSame([$old->getId()], $diff->archivedVariantIds);
    }

    public function testAlreadyArchivedAbsentVariantIsLeftAlone(): void
    {
        $absent = $this->absentVariant();
        $absent->archive();
        $this->em->flush();

        $diff = $this->analyzer->analyze(ImportFixtures::run($this->em, $this->supplier, $this->season, [self::row()]));

        self::assertSame([], $diff->archivedVariantIds);
        self::assertSame([], $diff->deactivatedVariantIds);
    }

    public function testStockReceivedAfterAnalysisPreventsArchiving(): void
    {
        $absent = $this->absentVariant();
        $run = $this->analyzedRun([self::row()]);
        $this->stockIn($absent);

        $this->applier->apply($run);

        self::assertFalse($absent->isActive());
        self::assertFalse($absent->isArchived());
    }

    public function testArchivedVariantBackInFileIsRestored(): void
    {
        $product = ImportFixtures::product($this->em, $this->supplier, 'R1');
        $variant = ImportFixtures::variant($this->em, $product, '688698508062', 'SR L', season: $this->season, club: 25000, public: 30000);
        $variant->archive();
        $this->em->flush();

        $diff = $this->analyzer->analyze(ImportFixtures::run($this->em, $this->supplier, $this->season, [self::row()]));
        self::assertSame([$variant->getId()], $diff->reactivatedVariantIds);

        $this->applier->apply($this->analyzedRun([self::row()]));

        self::assertTrue($variant->isActive());
        self::assertFalse($variant->isArchived());
    }

    public function testDiffWithoutArchivedKeyStillLoads(): void
    {
        $diff = ImportDiff::fromArray(['deactivatedVariantIds' => [1]]);

        self::assertSame([], $diff->archivedVariantIds);
        self::assertSame([1], $diff->deactivatedVariantIds);
        self::assertSame([5], ImportDiff::fromArray(['archivedVariantIds' => [5]] + $diff->toArray())->archivedVariantIds);
    }

    public function testPreviewSeparatesArchivedAndDeactivated(): void
    {
        $inStock = $this->absentVariant('688698508079');
        $this->stockIn($inStock);
        $product = $inStock->getProduct();
        self::assertNotNull($product);
        ImportFixtures::variant($this->em, $product, '688698508086', 'SR S');
        $run = $this->analyzedRun([self::row()]);
        $this->client->loginUser(UserFactory::create($this->em, UserRole::Manager, 'u@example.test'));

        $this->client->request('GET', '/imports/'.$run->getId());

        self::assertResponseIsSuccessful();
        $text = $this->client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('Absentes du fichier, en stock : désactivées (1)', $text);
        self::assertStringContainsString('Absentes du fichier, sans stock : archivées (1)', $text);
    }

    public function testFirstCategorizationKeepsModeWhenMovements(): void
    {
        $variant = StockScenario::variant($this->em, TrackingMode::Quantity, 'R1', 'Patin Vapor', '688698508062');
        $product = $variant->getProduct();
        self::assertInstanceOf(Product::class, $product);
        $this->stockIn($variant);
        $product->setCategory(null);
        $this->em->flush();

        $run = $this->analyzedRun([self::row(['Taille' => 'M'])]);
        $diff = ImportDiff::fromArray($run->getDiff() ?? []);
        $messages = array_column($diff->anomalies, 'message', 'code');
        self::assertSame(
            'Produit R1 : mode de suivi conservé, le produit a des mouvements de stock.',
            $messages[ImportAnomalyCode::TrackingModeLocked->value] ?? null,
        );

        $this->applier->apply($run);

        self::assertSame('EQUIPEMENT › JOUEUR', $product->getCategory()?->getPath());
        self::assertSame(TrackingMode::Quantity, $product->getTrackingMode());
    }

    public function testFirstCategorizationSetsModeWithoutMovements(): void
    {
        $variant = StockScenario::variant($this->em, TrackingMode::Quantity, 'R1', 'Patin Vapor', '688698508062');
        $product = $variant->getProduct();
        self::assertInstanceOf(Product::class, $product);
        $product->setCategory(null);
        $this->em->flush();

        $run = $this->analyzedRun([self::row(['Taille' => 'M'])]);
        $diff = ImportDiff::fromArray($run->getDiff() ?? []);
        self::assertNotContains(ImportAnomalyCode::TrackingModeLocked->value, array_column($diff->anomalies, 'code'));

        $this->applier->apply($run);

        self::assertSame(TrackingMode::Serialized, $product->getTrackingMode());
    }
}
