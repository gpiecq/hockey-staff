<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EntryControllerTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(KernelBrowser $client, UserRole $role): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, 'u@example.test'));
    }

    private function variant(KernelBrowser $client, TrackingMode $mode = TrackingMode::Quantity, ?string $ean = '1234567890123', string $ref = 'REF-1', bool $archived = false): Variant
    {
        $em = $this->em($client);
        $supplier = $em->getRepository(\App\Entity\Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($em);
        $product = ImportFixtures::product($em, $supplier, $ref);
        $product->setTrackingMode($mode);
        $variant = ImportFixtures::variant($em, $product, $ean, 'M');
        if ($archived) {
            $variant->archive();
            $em->flush();
        }

        return $variant;
    }

    private function rows(KernelBrowser $client, string $class): int
    {
        return (int) $this->em($client)->createQuery('SELECT COUNT(e.id) FROM '.$class.' e')->getSingleScalarResult();
    }

    /** @return array{location: string, documentReference: string, comment: string, lines: array<int, array<string, string>>} */
    private function payload(Location $location, Variant $variant, int $quantity, ?string $cost = null, string $serials = ''): array
    {
        $line = ['variant' => (string) $variant->getId(), 'quantity' => (string) $quantity, 'condition' => 'new', 'serialNumbers' => $serials];
        if (null !== $cost) {
            $line['unitCostHt'] = $cost;
        }

        return ['location' => (string) $location->getId(), 'documentReference' => 'BL-1', 'comment' => '', 'lines' => [$line]];
    }

    /** @param array<string, mixed> $values Le select d'autocomplete n'a pas d'option côté HTML : on poste les valeurs brutes. */
    private function submit(KernelBrowser $client, \Symfony\Component\DomCrawler\Crawler $crawler, array $values): void
    {
        $form = $crawler->selectButton('Enregistrer')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), ['stock_entry' => $values]));
    }

    public function testViewerCannotOpenEntry(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);

        $client->request('GET', '/stock/entree/achat');

        self::assertResponseStatusCodeSame(403);
    }

    public function testStockUserRecordsPurchase(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->variant($client);

        $crawler = $client->request('GET', '/stock/entree/achat');
        self::assertResponseIsSuccessful();
        $this->submit($client, $crawler, $this->payload($location, $variant, 3, '12,50'));

        $operation = $this->em($client)->getRepository(StockOperation::class)->findOneBy([]);
        self::assertNotNull($operation);
        self::assertResponseRedirects('/stock/operations/'.$operation->getId());
        $level = $this->em($client)->getRepository(StockLevel::class)->findOneBy([]);
        self::assertNotNull($level);
        self::assertSame(3, $level->getQuantity());
        self::assertSame(3750, $level->getValueHt());
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Entrée enregistrée.');
        self::assertSelectorTextContains('.table', "12,50\u{00A0}€");
    }

    public function testSerializedPurchaseShowsCreatedCodes(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->variant($client, TrackingMode::Serialized);

        $crawler = $client->request('GET', '/stock/entree/achat');
        $this->submit($client, $crawler, $this->payload($location, $variant, 2, '10,00'));
        $client->followRedirect();

        self::assertSelectorTextContains('.flash-success', '2 pièce(s) créée(s)');
        preg_match_all('/HS-[A-Z0-9-]+/', (string) $client->getResponse()->getContent(), $matches);
        self::assertGreaterThanOrEqual(2, \count(array_unique($matches[0])));
    }

    public function testDonationFormHasNoCostField(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        StockFixtures::location($this->em($client));

        $crawler = $client->request('GET', '/stock/entree/dotation');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('unitCostHt', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('textarea[name$="[serialNumbers]"]'));
    }

    public function testRecorderErrorIsShownAndNothingWritten(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->variant($client);

        $crawler = $client->request('GET', '/stock/entree/achat');
        $this->submit($client, $crawler, $this->payload($location, $variant, 1));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Coût unitaire manquant');
        self::assertSame(0, $this->rows($client, StockOperation::class));
    }

    public function testRerenderAfterErrorKeepsLineIndexesUnique(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->variant($client);

        $crawler = $client->request('GET', '/stock/entree/achat');
        $payload = $this->payload($location, $variant, 1);
        $payload['lines'][2] = $payload['lines'][0];
        $this->submit($client, $crawler, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[name="stock_entry[lines][2][quantity]"]');
        self::assertSame('3', $client->getCrawler()->filter('[data-controller="form-collection"]')->attr('data-form-collection-index-value'));
    }

    public function testNoActiveLocationShowsHint(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $client->request('GET', '/stock/entree/achat');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', "Créez d'abord un lieu");
        self::assertSelectorExists('main a[href="/lieux/new"]');
    }

    public function testEntryOnDeactivatedVariantIsRefused(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->variant($client);
        $variant->setActive(false);
        $this->em($client)->flush();

        $crawler = $client->request('GET', '/stock/entree/achat');
        $this->submit($client, $crawler, $this->payload($location, $variant, 2, '10.00'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->rows($client, StockLevel::class));
    }

    public function testAutocompleteFindsByTwelveDigitEanAndHidesArchived(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $this->variant($client, TrackingMode::Quantity, '0123456789012', 'REF-OK');
        $this->variant($client, TrackingMode::Quantity, null, 'REF-ARCH', true);

        $client->request('GET', '/autocomplete/stock_variant_autocomplete_field?query=123456789012');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('REF-OK', $body);

        $client->request('GET', '/autocomplete/stock_variant_autocomplete_field?query=REF-');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('REF-OK', $body);
        self::assertStringNotContainsString('REF-ARCH', $body);
    }

    public function testStockAutocompleteFindsVariantByCode(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->variant($client, TrackingMode::Quantity, null, 'REF-OK');
        $this->variant($client, TrackingMode::Quantity, null, 'REF-OTHER');

        $client->request('GET', '/autocomplete/stock_variant_autocomplete_field?query='.strtolower((string) $variant->getCode()));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('REF-OK', $body);
        self::assertStringNotContainsString('REF-OTHER', $body);
    }
}
