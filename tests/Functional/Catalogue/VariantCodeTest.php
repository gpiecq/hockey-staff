<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Variant;
use App\Enum\UserRole;
use App\Import\CatalogueImportAnalyzer;
use App\Import\CatalogueImportApplier;
use App\Repository\CategoryRepository;
use App\Repository\VariantRepository;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VariantCodeTest extends WebTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    protected function tearDown(): void
    {
        ImportFixtures::cleanup();
        parent::tearDown();
    }

    private function repository(): VariantRepository
    {
        $repository = $this->em->getRepository(Variant::class);
        \assert($repository instanceof VariantRepository);

        return $repository;
    }

    /** @return array{Variant, Variant} */
    private function twoVariants(): array
    {
        $product = ImportFixtures::product($this->em, ImportFixtures::supplierWithMapping($this->em), 'A1');

        return [
            ImportFixtures::variant($this->em, $product, null, 'SR L'),
            ImportFixtures::variant($this->em, $product, null, 'SR M'),
        ];
    }

    public function testNewVariantsGetSequentialCodes(): void
    {
        [$first, $second] = $this->twoVariants();

        self::assertMatchesRegularExpression('/^HV-\d{6}$/', (string) $first->getCode());
        self::assertMatchesRegularExpression('/^HV-\d{6}$/', (string) $second->getCode());
        self::assertSame((int) substr((string) $first->getCode(), 3) + 1, (int) substr((string) $second->getCode(), 3));
    }

    public function testCodeIsImmutable(): void
    {
        [$variant] = $this->twoVariants();
        $code = $variant->getCode();

        try {
            $variant->assignCode('HV-999999');
            self::fail('Le code déjà posé ne doit pas pouvoir changer.');
        } catch (\LogicException) {
            self::assertSame($code, $variant->getCode());
        }
    }

    public function testFindOneByCodeIgnoresCaseAndSpaces(): void
    {
        [$variant] = $this->twoVariants();
        $code = (string) $variant->getCode();

        self::assertSame($variant, $this->repository()->findOneByCode('  '.strtolower($code).' '));
        self::assertNull($this->repository()->findOneByCode('HV-000000'));
    }

    public function testImportedVariantGetsCode(): void
    {
        $analyzer = self::getContainer()->get(CatalogueImportAnalyzer::class);
        $applier = self::getContainer()->get(CatalogueImportApplier::class);
        $categories = self::getContainer()->get(CategoryRepository::class);
        \assert($analyzer instanceof CatalogueImportAnalyzer && $applier instanceof CatalogueImportApplier && $categories instanceof CategoryRepository);
        $categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']);
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $run = ImportFixtures::run($this->em, $supplier, ImportFixtures::season($this->em), [[
            'Référence interne' => 'R1', 'Gencode' => '688698508062', 'Désignation' => 'Patin Vapor', 'Taille' => 'SR L',
            'PU Public TTC' => '300', 'PU Club TTC' => '250', 'Catégorie' => 'EQUIPEMENT', 'sous-catégorie 1' => 'JOUEUR',
            'Marque' => 'BAUER',
        ]]);
        $run->markAnalyzed($analyzer->analyze($run)->toArray());
        $this->em->flush();

        $applier->apply($run);

        $variant = $this->em->getRepository(Variant::class)->findOneBy(['ean' => '688698508062']);
        self::assertNotNull($variant);
        self::assertMatchesRegularExpression('/^HV-\d{6}$/', (string) $variant->getCode());
    }

    public function testProductPageShowsVariantCode(): void
    {
        [$variant] = $this->twoVariants();
        $client = self::getClient();
        \assert($client instanceof KernelBrowser);
        $client->loginUser(UserFactory::create($this->em, UserRole::Viewer, 'v@example.test'));

        $client->request('GET', '/catalogue/'.$variant->getProduct()?->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $variant->getCode());
    }
}
