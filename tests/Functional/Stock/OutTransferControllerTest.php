<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\StockMovementType;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class OutTransferControllerTest extends WebTestCase
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

    private function receive(KernelBrowser $client, TrackingMode $mode, Location $location, int $quantity): Variant
    {
        $em = $this->em($client);
        $supplier = $em->getRepository(Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($em);
        $product = ImportFixtures::product($em, $supplier, 'REF-1');
        $product->setTrackingMode($mode);
        $variant = ImportFixtures::variant($em, $product, '1234567890123', 'M');
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [new StockEntryLine($variant, $quantity, 1000)]));

        return $variant;
    }

    private function item(KernelBrowser $client): Item
    {
        $item = $this->em($client)->getRepository(Item::class)->findOneBy([]);
        self::assertNotNull($item);

        return $item;
    }

    private function quantity(KernelBrowser $client, Variant $variant, Location $location): ?int
    {
        $this->em($client)->clear();

        return $this->em($client)->getRepository(StockLevel::class)->findOneBy(['variant' => $variant->getId(), 'location' => $location->getId()])?->getQuantity();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submit(KernelBrowser $client, Crawler $crawler, string $name, array $values): void
    {
        $form = $crawler->selectButton('Enregistrer')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), [$name => $values]));
    }

    public function testOutByItemCode(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $this->receive($client, TrackingMode::Serialized, $location, 1);
        $item = $this->item($client);

        $crawler = $client->request('GET', '/stock/sortie');
        self::assertResponseIsSuccessful();
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'broken',
            'comment' => '',
            'lines' => [['itemCode' => strtolower($item->getCode()), 'variant' => '', 'quantity' => '']],
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Sortie enregistrée.');
        $this->em($client)->clear();
        self::assertSame(ItemStatus::Removed, $this->item($client)->getStatus());
    }

    public function testOutByQuantity(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->receive($client, TrackingMode::Quantity, $location, 3);

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'lost',
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '2', 'itemCode' => '']],
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->quantity($client, $variant, $location));
    }

    public function testUnknownItemCodeIsFieldError(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'broken',
            'comment' => '',
            'lines' => [['itemCode' => 'hs-999999', 'variant' => '', 'quantity' => '']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Pièce HS-999999 introuvable.');
    }

    public function testLineWithoutVariantNorCodeIsFieldError(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'broken',
            'comment' => '',
            'lines' => [['itemCode' => '', 'variant' => '', 'quantity' => '']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Indiquez une variante ou un code de pièce.');
    }

    public function testInsufficientStockShowsRecorderMessage(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->receive($client, TrackingMode::Quantity, $location, 1);

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'lost',
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '5', 'itemCode' => '']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Stock insuffisant');
    }

    public function testTransferScreen(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $a = StockFixtures::location($this->em($client), 'Local A');
        $b = StockFixtures::location($this->em($client), 'Local B');
        $variant = $this->receive($client, TrackingMode::Quantity, $a, 3);

        $crawler = $client->request('GET', '/stock/transfert');
        self::assertResponseIsSuccessful();
        $this->submit($client, $crawler, 'stock_transfer', [
            'from' => (string) $a->getId(),
            'to' => (string) $b->getId(),
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '1', 'itemCode' => '']],
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Transfert enregistré.');
        self::assertSame(2, $this->quantity($client, $variant, $a));
        self::assertSame(1, $this->quantity($client, $variant, $b));
    }

    public function testItemPrefill(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        StockFixtures::location($this->em($client), 'Autre lieu');
        $location = StockFixtures::location($this->em($client), 'Lieu pièce');
        $this->receive($client, TrackingMode::Serialized, $location, 1);
        $item = $this->item($client);

        $crawler = $client->request('GET', '/stock/sortie?item='.$item->getCode());

        self::assertResponseIsSuccessful();
        self::assertSame($item->getCode(), $crawler->filter('input[name="stock_out[lines][0][itemCode]"]')->attr('value'));
        self::assertSame((string) $location->getId(), $crawler->filter('select[name="stock_out[location]"] option[selected]')->attr('value'));
    }

    public function testUnknownItemPrefillOpensEmptyForm(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);

        $crawler = $client->request('GET', '/stock/transfert?item=HS-999999');

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $crawler->filter('input[name="stock_transfer[lines][0][itemCode]"]')->attr('value'));
    }

    public function testViewerCannotOpenOutOrTransfer(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);

        $client->request('GET', '/stock/sortie');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/stock/transfert');
        self::assertResponseStatusCodeSame(403);
    }
}
