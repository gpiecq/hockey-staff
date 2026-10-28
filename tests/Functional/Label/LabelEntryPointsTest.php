<?php

declare(strict_types=1);

namespace App\Tests\Functional\Label;

use App\Entity\Product;
use App\Enum\ProductKind;
use App\Enum\StockMovementType;
use App\Enum\StockOutReason;
use App\Enum\UserRole;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockOut;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LabelEntryPointsTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(KernelBrowser $client, UserRole $role, string $email = 'u@example.test'): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, $email));
    }

    private function recorder(KernelBrowser $client): StockMovementRecorder
    {
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);

        return $recorder;
    }

    /** @return array{Product, Product} produit avec EAN valide, produit sans EAN + variante à clé invalide */
    private function eanProducts(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $supplier = ImportFixtures::supplierWithMapping($em);
        $valid = ImportFixtures::product($em, $supplier, 'REF-OK', 'Casque valide');
        ImportFixtures::variant($em, $valid, '4006381333931', 'M');
        $missing = ImportFixtures::product($em, $supplier, 'REF-NO', 'Gant sans ean');
        ImportFixtures::variant($em, $missing, null, 'S');
        ImportFixtures::variant($em, $missing, '876369003339', 'L');
        $em->flush();

        return [$valid, $missing];
    }

    public function testProductPageOffersVariantCheckboxes(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        [, $product] = $this->eanProducts($client);

        $client->request('GET', '/catalogue/'.$product->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'input[type="checkbox"][name="variantes[]"][checked]');
        self::assertSelectorExists('form[action="/etiquettes"][method="get"] button[type="submit"]');
        self::assertSelectorTextContains('form[action="/etiquettes"]', 'Imprimer des étiquettes');

        $this->login($client, UserRole::Viewer, 'viewer@example.test');
        $client->request('GET', '/catalogue/'.$product->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="variantes[]"]');
        self::assertSelectorNotExists('form[action="/etiquettes"]');
    }

    public function testCatalogueSelectionLeadsToPrintScreen(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        [$valid] = $this->eanProducts($client);

        $client->request('GET', '/catalogue');
        self::assertSelectorCount(2, 'input[type="checkbox"][name="produits[]"][form="label-selection"]');
        self::assertSelectorTextContains('body', 'Étiquettes de la sélection');
        self::assertSelectorExists('a[href="/etiquettes?sans_ean=1"]');
        self::assertSelectorExists('form#label-selection[action="/etiquettes"][data-turbo-frame="_top"]');

        $client->request('GET', '/etiquettes', ['produits' => [(string) $valid->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', '4006381333931');
        self::assertSelectorTextNotContains('table', 'Gant sans ean');

        $this->login($client, UserRole::Viewer, 'viewer@example.test');
        $client->request('GET', '/catalogue');
        self::assertSelectorNotExists('input[name="produits[]"]');
        self::assertSelectorNotExists('a[href="/etiquettes?sans_ean=1"]');
    }

    public function testWithoutEanButtonListsVariantsWithoutValidEan(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $this->eanProducts($client);

        $client->request('GET', '/etiquettes', ['sans_ean' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'tbody tr');
        self::assertSelectorTextContains('table', 'Gant sans ean');
        self::assertSelectorTextNotContains('table', 'Casque valide');
    }

    public function testSansEanFilter(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        $this->eanProducts($client);

        $client->request('GET', '/catalogue');
        self::assertSelectorExists('input[name="sansEan"]');
        self::assertSelectorTextContains('table', 'Casque valide');

        $client->request('GET', '/catalogue?sansEan=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Gant sans ean');
        self::assertSelectorTextNotContains('table', 'Casque valide');
    }

    public function testEntryOperationOffersLabels(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $em = $this->em($client);
        $serialized = StockScenario::variant($em, ProductKind::Equipment, 'REF-S', 'Casque', '4006381333931');
        $quantity = StockScenario::variant($em, ProductKind::Consumable, 'REF-Q', 'Ruban', '5901234123457');
        $location = StockFixtures::location($em, 'Local A');
        $recorder = $this->recorder($client);
        $entry = $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [
            new StockEntryLine($serialized, 2, 5000),
            new StockEntryLine($quantity, 7, 300),
        ]));
        $out = $recorder->recordOut(new StockOut($location, StockOutReason::Lost, [new StockLine($quantity, 1)]));

        $crawler = $client->request('GET', '/stock/operations/'.$entry->getId());
        self::assertResponseIsSuccessful();
        $href = '/etiquettes?operation='.$entry->getId();
        self::assertSelectorExists('a[href="'.$href.'"]');

        $client->click($crawler->filter('a[href="'.$href.'"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'tbody tr');
        self::assertSelectorExists('input[name$="[exemplaires]"][value="7"]');

        $client->request('GET', '/stock/operations/'.$out->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href^="/etiquettes"]');
    }
}
