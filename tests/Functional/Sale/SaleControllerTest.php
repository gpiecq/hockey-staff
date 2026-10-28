<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\SeasonPrice;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Enum\StockMovementType;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SaleControllerTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(KernelBrowser $client, UserRole $role = UserRole::Manager, string $email = 'u@example.test'): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, $email));
    }

    /**
     * Un casque au Local : une pièce par état donné ; tarif public de la saison courante si $publicPrice.
     *
     * @param list<ItemCondition> $conditions
     *
     * @return array{Variant, Location, list<Item>}
     */
    private function helmet(KernelBrowser $client, array $conditions, ?int $publicPrice = 30000): array
    {
        $em = $this->em($client);
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, TrackingMode::Serialized, 'CASQUE-1', 'Casque Re-Akt');
        if (null !== $publicPrice) {
            $em->persist((new SeasonPrice())->setVariant($helmet)->setSeason(PlayerFixtures::season($em, 2026, true))->setPublicPriceTtc($publicPrice)->setVatRate(2000));
            $em->flush();
        }
        $items = [];
        foreach ($conditions as $condition) {
            $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $local, [new StockEntryLine($helmet, 1, 15000, $condition)]));
            $item = $em->getRepository(Item::class)->findOneBy(['variant' => $helmet], ['id' => 'DESC']);
            \assert($item instanceof Item);
            $items[] = $item;
        }

        return [$helmet, $local, $items];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submit(KernelBrowser $client, string $url, array $values): void
    {
        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Enregistrer')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), ['sale' => $values]));
    }

    private function text(KernelBrowser $client): string
    {
        return str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
    }

    public function testManagerSellsFromVariantSheet(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $items] = $this->helmet($client, [ItemCondition::Good]);

        $crawler = $client->request('GET', '/ventes/new?variant='.$helmet->getId());
        self::assertResponseIsSuccessful();
        self::assertSame($items[0]->getCode(), $crawler->filter('input[name="sale[lines][0][itemCode]"]')->attr('value'));

        $this->submit($client, '/ventes/new?variant='.$helmet->getId(), [
            'buyer' => 'M. Petit, 06 00 00 00 00',
            'lines' => [0 => ['discount' => '80']],
        ]);

        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#^/ventes/\d+$#', (string) $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        $text = $this->text($client);
        self::assertStringContainsString('M. Petit, 06 00 00 00 00', $text);
        self::assertStringContainsString('300,00 €', $text);
        self::assertStringContainsString('60,00 €', $text);
        self::assertStringContainsString('50,00 €', $text);
        self::assertStringContainsString($items[0]->getCode(), $text);
        self::assertSame($local->getId(), $items[0]->getLocation()->getId());
    }

    public function testTamperedLineIsRejected(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, , $items] = $this->helmet($client, [ItemCondition::Good, ItemCondition::New]);

        $this->submit($client, '/ventes/new?variant='.$helmet->getId(), [
            'buyer' => 'M. Petit',
            'lines' => [0 => ['itemCode' => $items[1]->getCode(), 'discount' => '80']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('est neuve', $this->text($client));
    }

    public function testUnknownCodeIsRejected(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet] = $this->helmet($client, [ItemCondition::Good]);

        $this->submit($client, '/ventes/new?variant='.$helmet->getId(), [
            'buyer' => 'M. Petit',
            'lines' => [0 => ['itemCode' => 'HS-999999', 'discount' => '80']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('introuvable', $this->text($client));
    }

    public function testBlankBuyerIsRejected(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet] = $this->helmet($client, [ItemCondition::Good]);

        $this->submit($client, '/ventes/new?variant='.$helmet->getId(), ['buyer' => '  ', 'lines' => [0 => ['discount' => '80']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Indiquez l\'acheteur', $this->text($client));
    }

    public function testStockUserCannotSell(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);

        $client->request('GET', '/ventes/new');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/ventes');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSaleListShowsTotal(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, , $items] = $this->helmet($client, [ItemCondition::Good, ItemCondition::Worn], null);

        $this->submit($client, '/ventes/new?variant='.$helmet->getId(), [
            'buyer' => 'Mme Dupont',
            'lines' => [
                0 => ['itemCode' => $items[0]->getCode(), 'finalPrice' => '60'],
                1 => ['itemCode' => $items[1]->getCode(), 'finalPrice' => '40'],
            ],
        ]);
        self::assertResponseRedirects();

        $client->request('GET', '/ventes');

        self::assertResponseIsSuccessful();
        $text = $this->text($client);
        self::assertStringContainsString('Mme Dupont', $text);
        self::assertStringContainsString('100,00 €', $text);
    }

    public function testSalesMenuEntryIsForManagersOnly(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $client->request('GET', '/stock');
        self::assertGreaterThan(0, $client->getCrawler()->filter('nav.sidebar a[href="/ventes"]')->count());

        $stock = $client;
        $this->login($stock, UserRole::Stock, 's@example.test');
        $stock->request('GET', '/stock');
        self::assertSame(0, $stock->getCrawler()->filter('nav.sidebar a[href="/ventes"]')->count());
    }
}
