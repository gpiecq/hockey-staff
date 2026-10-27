<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Product;
use App\Entity\Variant;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogueStockGuardsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->client->loginUser(UserFactory::create($this->em, UserRole::Manager, 'u@example.test'));
    }

    private function stocked(string $reference = 'R1', string $designation = 'Casque stocké'): Variant
    {
        $recorder = $this->client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $variant = StockScenario::variant($this->em, TrackingMode::Quantity, $reference, $designation);
        StockScenario::receive($recorder, $variant, StockFixtures::location($this->em), 2, 1000);

        return $variant;
    }

    public function testTrackingModeLockedInProductForm(): void
    {
        $product = $this->stocked()->getProduct();
        self::assertNotNull($product);
        $id = $product->getId();

        $crawler = $this->client->request('GET', '/catalogue/'.$id.'/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('input[type=radio][name="product[trackingMode]"][disabled]'));
        self::assertStringContainsString(
            'Le produit a des mouvements de stock : le mode de suivi ne peut plus changer.',
            $crawler->filter('form')->text(),
        );

        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['product']['trackingMode'] = 'serialized';
        $this->client->request('POST', $form->getUri(), $values);

        $this->em->clear();
        self::assertSame(TrackingMode::Quantity, $this->em->find(Product::class, $id)?->getTrackingMode());
    }

    public function testTrackingModeEditableWithoutMovements(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, 'R9');

        $crawler = $this->client->request('GET', '/catalogue/'.$product->getId().'/edit');

        self::assertCount(0, $crawler->filter('input[type=radio][name="product[trackingMode]"][disabled]'));
    }

    public function testVariantWithMovementsCannotBeDeleted(): void
    {
        $variant = $this->stocked();
        $id = $variant->getId();
        $productId = $variant->getProduct()?->getId();

        $this->client->request('GET', '/catalogue/variantes/'.$id.'/delete');
        $this->client->submit($this->client->getCrawler()->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/catalogue/'.$productId);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Cette variante a des mouvements de stock : elle ne peut pas être supprimée.');
        $this->em->clear();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT deleted_at FROM variant WHERE id = ?', [$id]));
        self::assertInstanceOf(Variant::class, $this->em->find(Variant::class, $id));
    }

    public function testProductWithMovementsCannotBeDeleted(): void
    {
        $product = $this->stocked()->getProduct();
        self::assertNotNull($product);
        $id = $product->getId();

        $this->client->request('GET', '/catalogue/'.$id.'/delete');
        $this->client->submit($this->client->getCrawler()->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/catalogue/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Ce produit a des mouvements de stock : il ne peut pas être supprimé.');
        $this->em->clear();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT deleted_at FROM product WHERE id = ?', [$id]));
        self::assertInstanceOf(Product::class, $this->em->find(Product::class, $id));
    }

    public function testArchivedProductsHiddenByDefault(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, 'ARCH1', 'Produit archivé');
        ImportFixtures::variant($this->em, $product, null, 'M')->archive();
        $this->em->flush();

        $this->client->request('GET', '/catalogue');
        self::assertStringNotContainsString('Produit archivé', $this->client->getCrawler()->filter('body')->text());

        $this->client->request('GET', '/catalogue?includeArchived=1');
        self::assertStringContainsString('Produit archivé', $this->client->getCrawler()->filter('body')->text());
    }

    public function testArchivedBadgeOnProductPage(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, 'ARCH2', 'Produit archivé');
        ImportFixtures::variant($this->em, $product, null, 'M')->archive();
        $this->em->flush();

        $this->client->request('GET', '/catalogue/'.$product->getId());

        self::assertSelectorTextContains('table .badge', 'Archivée');
    }
}
