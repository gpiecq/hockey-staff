<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
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

    private function receive(KernelBrowser $client, ProductKind $mode, Location $location, int $quantity, StockCondition $condition = StockCondition::New): Variant
    {
        $em = $this->em($client);
        $supplier = $em->getRepository(Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($em);
        $product = ImportFixtures::product($em, $supplier, 'REF-1');
        $product->setKind($mode);
        $variant = ImportFixtures::variant($em, $product, '1234567890123', 'M');
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [new StockEntryLine($variant, $quantity, 1000, $condition)]));

        return $variant;
    }

    private function quantity(KernelBrowser $client, Variant $variant, Location $location, StockCondition $condition = StockCondition::New): ?int
    {
        $this->em($client)->clear();

        return $this->em($client)->getRepository(StockLevel::class)->findOneBy(['variant' => $variant->getId(), 'location' => $location->getId(), 'condition' => $condition])?->getQuantity();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submit(KernelBrowser $client, Crawler $crawler, string $name, array $values): void
    {
        $form = $crawler->selectButton('Enregistrer')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), [$name => $values]));
    }

    public function testOutOfEquipmentByCondition(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->receive($client, ProductKind::Equipment, $location, 2, StockCondition::Worn);

        $crawler = $client->request('GET', '/stock/sortie');
        self::assertResponseIsSuccessful();
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'broken',
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '1', 'condition' => 'worn']],
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Sortie enregistrée.');
        self::assertSame(1, $this->quantity($client, $variant, $location, StockCondition::Worn));
    }

    public function testTransferOfEquipmentKeepsCondition(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $a = StockFixtures::location($this->em($client), 'Local A');
        $b = StockFixtures::location($this->em($client), 'Local B');
        $variant = $this->receive($client, ProductKind::Equipment, $a, 2, StockCondition::Good);

        $crawler = $client->request('GET', '/stock/transfert');
        $this->submit($client, $crawler, 'stock_transfer', [
            'from' => (string) $a->getId(),
            'to' => (string) $b->getId(),
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '1', 'condition' => 'good']],
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->quantity($client, $variant, $a, StockCondition::Good));
        self::assertSame(1, $this->quantity($client, $variant, $b, StockCondition::Good));
        self::assertNull($this->quantity($client, $variant, $b));
    }

    public function testOutFormHasConditionAndNoItemCode(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        StockFixtures::location($this->em($client));

        foreach (['/stock/sortie', '/stock/transfert'] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful();
            $page = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('itemCode', $page);
            self::assertStringNotContainsString('Code de la pièce', $page);
            self::assertCount(1, $crawler->filter('select[name$="[lines][0][condition]"]'));
            self::assertSame('new', $crawler->filter('select[name$="[lines][0][condition]"] option[selected]')->attr('value'));
            self::assertSelectorTextContains('.entry-line', 'État');
            self::assertSelectorTextContains('.entry-line', 'Ignoré pour un consommable.');
        }
    }

    public function testOutByQuantity(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->receive($client, ProductKind::Consumable, $location, 3);

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'lost',
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '2']],
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->quantity($client, $variant, $location));
    }

    public function testLineWithoutVariantIsFieldError(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'broken',
            'comment' => '',
            'lines' => [['variant' => '', 'quantity' => '']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Choisissez un article.');
    }

    public function testInsufficientStockShowsRecorderMessage(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->receive($client, ProductKind::Consumable, $location, 1);

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'lost',
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '5']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Stock insuffisant');
    }

    /** @return array<string, array{bool}> */
    public static function removedFromCatalogue(): array
    {
        return ['deactivated by import' => [false], 'archived' => [true]];
    }

    private function leaveCatalogue(KernelBrowser $client, Variant $variant, bool $archive): void
    {
        $em = $this->em($client);
        $variant = $em->getRepository(Variant::class)->find($variant->getId()) ?? $variant;
        if ($archive) {
            $variant->archive();
        }
        $variant->setActive(false);
        $em->flush();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('removedFromCatalogue')]
    public function testOutOfQuantityVariantRemovedFromCatalogue(bool $archive): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client));
        $variant = $this->receive($client, ProductKind::Consumable, $location, 3);
        $this->leaveCatalogue($client, $variant, $archive);

        $crawler = $client->request('GET', '/stock/sortie');
        $this->submit($client, $crawler, 'stock_out', [
            'location' => (string) $location->getId(),
            'reason' => 'lost',
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '2']],
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->quantity($client, $variant, $location));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('removedFromCatalogue')]
    public function testTransferOfQuantityVariantRemovedFromCatalogue(bool $archive): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $a = StockFixtures::location($this->em($client), 'Local A');
        $b = StockFixtures::location($this->em($client), 'Local B');
        $variant = $this->receive($client, ProductKind::Consumable, $a, 3);
        $this->leaveCatalogue($client, $variant, $archive);

        $crawler = $client->request('GET', '/stock/transfert');
        $this->submit($client, $crawler, 'stock_transfer', [
            'from' => (string) $a->getId(),
            'to' => (string) $b->getId(),
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '1']],
        ]);

        self::assertResponseRedirects();
        self::assertSame(2, $this->quantity($client, $variant, $a));
        self::assertSame(1, $this->quantity($client, $variant, $b));
    }

    public function testTransferScreen(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $a = StockFixtures::location($this->em($client), 'Local A');
        $b = StockFixtures::location($this->em($client), 'Local B');
        $variant = $this->receive($client, ProductKind::Consumable, $a, 3);

        $crawler = $client->request('GET', '/stock/transfert');
        self::assertResponseIsSuccessful();
        $this->submit($client, $crawler, 'stock_transfer', [
            'from' => (string) $a->getId(),
            'to' => (string) $b->getId(),
            'comment' => '',
            'lines' => [['variant' => (string) $variant->getId(), 'quantity' => '1']],
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Transfert enregistré.');
        self::assertSame(2, $this->quantity($client, $variant, $a));
        self::assertSame(1, $this->quantity($client, $variant, $b));
    }

    public function testOutFormPrefilledFromVariant(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        StockFixtures::location($this->em($client), 'Autre lieu');
        $location = StockFixtures::location($this->em($client), 'Lieu prérempli');
        $variant = $this->receive($client, ProductKind::Consumable, $location, 2);

        $crawler = $client->request('GET', '/stock/sortie?variant='.$variant->getId().'&lieu='.$location->getId());

        self::assertResponseIsSuccessful();
        self::assertSame((string) $variant->getId(), $crawler->filter('select[name="stock_out[lines][0][variant]"] option[selected]')->attr('value'));
        self::assertSame((string) $location->getId(), $crawler->filter('select[name="stock_out[location]"] option[selected]')->attr('value'));
    }

    public function testTransferFormPrefilledFromVariantAndLocation(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $location = StockFixtures::location($this->em($client), 'Lieu source');
        $variant = $this->receive($client, ProductKind::Consumable, $location, 2);

        $crawler = $client->request('GET', '/stock/transfert?variant='.$variant->getId().'&lieu='.$location->getId());

        self::assertResponseIsSuccessful();
        self::assertSame((string) $variant->getId(), $crawler->filter('select[name="stock_transfer[lines][0][variant]"] option[selected]')->attr('value'));
        self::assertSame((string) $location->getId(), $crawler->filter('select[name="stock_transfer[from]"] option[selected]')->attr('value'));
    }

    public function testInvalidPrefillOpensEmptyForm(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $inactive = StockFixtures::location($this->em($client), 'Lieu inactif');
        $inactive->setActive(false);
        $this->em($client)->flush();

        $crawler = $client->request('GET', '/stock/transfert?variant=999999&lieu='.$inactive->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="stock_transfer[lines][0][variant]"] option[selected]:not([value=""])'));
        self::assertCount(0, $crawler->filter('select[name="stock_transfer[from]"] option[selected]:not([value=""])'));

        $client->request('GET', '/stock/sortie?variant=abc&lieu=xyz&item=HS-000001');
        self::assertResponseIsSuccessful();
    }

    public function testItemPageIsGone(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);

        $client->request('GET', '/stock/pieces/HS-000001');

        self::assertResponseStatusCodeSame(404);
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
