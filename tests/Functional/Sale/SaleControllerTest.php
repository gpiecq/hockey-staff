<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Entity\Location;
use App\Entity\Sale;
use App\Entity\SeasonPrice;
use App\Entity\StockLevel;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\UserRole;
use App\Repository\SaleRepository;
use App\Stock\Input\StockSale;
use App\Stock\Input\StockSaleLine;
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
     * Un casque au « Local matériel » : Neuf 2, Bon 1, Usé 3 ; tarif public de la saison courante si $publicPrice.
     *
     * @return array{Variant, Location}
     */
    private function helmet(KernelBrowser $client, ?int $publicPrice = 10000): array
    {
        $em = $this->em($client);
        $local = StockFixtures::location($em, 'Local matériel');
        $helmet = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-1', 'Casque Re-Akt');
        if (null !== $publicPrice) {
            $em->persist((new SeasonPrice())->setVariant($helmet)->setSeason(PlayerFixtures::season($em, 2026, true))->setPublicPriceTtc($publicPrice)->setVatRate(2000));
            $em->flush();
        }
        StockFixtures::level($em, $helmet, $local, 2, 15000);
        StockFixtures::level($em, $helmet, $local, 1, 15000, StockCondition::Good);
        StockFixtures::level($em, $helmet, $local, 3, 15000, StockCondition::Worn);

        return [$helmet, $local];
    }

    private function url(Variant $variant, Location $location, StockCondition $condition = StockCondition::Worn): string
    {
        return '/ventes/new?variant='.$variant->getId().'&etat='.$condition->value.'&lieu='.$location->getId();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submit(KernelBrowser $client, string $url, array $values, string $button = 'Enregistrer'): void
    {
        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton($button)->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), ['sale' => $values]));
    }

    private function text(KernelBrowser $client): string
    {
        return str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
    }

    private function saleCount(KernelBrowser $client): int
    {
        return $this->em($client)->getRepository(Sale::class)->count([]);
    }

    public function testManagerSellsFromVariantSheet(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);

        $crawler = $client->request('GET', $this->url($helmet, $local));
        self::assertResponseIsSuccessful();
        self::assertSame((string) $local->getId(), $crawler->filter('select[name="sale[location]"] option[selected]')->attr('value'));
        self::assertSame($helmet->getId().'-worn', $crawler->filter('select[name="sale[lines][0][stock]"] option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('input[name="sale[lines][0][quantity]"]')->attr('value'));

        $this->submit($client, $this->url($helmet, $local), [
            'buyer' => 'M. Petit, 06 00 00 00 00',
            'lines' => [0 => ['quantity' => '2', 'discount' => '80']],
        ]);

        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#^/ventes/\d+$#', (string) $client->getResponse()->headers->get('Location'));
        $level = $this->em($client)->getRepository(StockLevel::class)->findOneBy(['variant' => $helmet, 'condition' => StockCondition::Worn]);
        self::assertInstanceOf(StockLevel::class, $level);
        $this->em($client)->refresh($level);
        self::assertSame(1, $level->getQuantity());
    }

    public function testSaleFormListsSecondHandAtChosenLocation(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $em = $this->em($client);
        $vestiaire = StockFixtures::location($em, 'Vestiaire');
        $gloves = StockScenario::variant($em, ProductKind::Equipment, 'GANTS-1', 'Gants');
        StockFixtures::level($em, $gloves, $vestiaire, 5, 5000, StockCondition::Worn);
        StockFixtures::level($em, $helmet, $vestiaire, 4, 15000, StockCondition::Damaged);

        $crawler = $client->request('GET', '/ventes/new?lieu='.$local->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#sale-form[data-controller="variant-location"]'));
        self::assertSame('variant-location#change', $crawler->filter('select[name="sale[location]"]')->attr('data-action'));
        $options = $crawler->filter('select[name="sale[lines][0][stock]"] option:not([value=""])')->each(static fn ($option): array => [(string) $option->attr('value'), trim($option->text())]);
        self::assertSame([
            [$helmet->getId().'-good', 'Casque Re-Akt · M — Bon (1)'],
            [$helmet->getId().'-worn', 'Casque Re-Akt · M — Usé (3)'],
        ], $options);

        // Bloc du prix final : libellé avec l'unité, aucun « € » orphelin à côté du champ.
        $block = $crawler->filter('.sale-final-price');
        self::assertCount(1, $block);
        self::assertSame('Prix final unitaire TTC (€)', trim($block->filter('label')->text()));
        self::assertSame(1, substr_count($block->text(), '€'));

        // Sans lieu (plusieurs lieux actifs) : aucun article proposé, aide.
        $crawler = $client->request('GET', '/ventes/new');
        self::assertCount(0, $crawler->filter('select[name="sale[lines][0][stock]"] option:not([value=""])'));
        self::assertStringContainsString('Choisissez d\'abord le lieu.', $this->text($client));
    }

    public function testSaleShowAndIndexShowQuantities(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);

        $this->submit($client, $this->url($helmet, $local), [
            'buyer' => 'Mme Dupont',
            'lines' => [
                0 => ['stock' => $helmet->getId().'-worn', 'quantity' => '2', 'discount' => '80'],
                1 => ['stock' => $helmet->getId().'-good', 'quantity' => '1', 'finalPrice' => '45'],
            ],
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();

        $rows = $client->getCrawler()->filter('table tbody tr');
        self::assertCount(2, $rows);
        $worn = str_replace("\u{00A0}", ' ', $rows->eq(0)->text());
        self::assertStringContainsString('Casque Re-Akt', $worn);
        self::assertStringContainsString('Usé', $worn);
        self::assertSame('2', trim($rows->eq(0)->filter('td[data-label="Quantité"]')->text()));
        self::assertSame('20,00 €', str_replace("\u{00A0}", ' ', trim($rows->eq(0)->filter('td[data-label="Prix unitaire TTC"]')->text())));
        self::assertSame('40,00 €', str_replace("\u{00A0}", ' ', trim($rows->eq(0)->filter('td[data-label="Total TTC"]')->text())));
        self::assertStringContainsString('85,00 €', str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('table tfoot')->text()));

        $client->request('GET', '/ventes');
        self::assertResponseIsSuccessful();
        $row = $client->getCrawler()->filter('table tbody tr')->first();
        self::assertSame('3', trim($row->filter('td[data-label="Articles"]')->text()));
        self::assertSame('85,00 €', str_replace("\u{00A0}", ' ', trim($row->filter('td[data-label="Total TTC"]')->text())));

        $sales = $client->getContainer()->get(SaleRepository::class);
        \assert($sales instanceof SaleRepository);
        $sale = $sales->findOneBy(['buyer' => 'Mme Dupont']);
        self::assertInstanceOf(Sale::class, $sale);
        self::assertSame([(int) $sale->getId() => ['articles' => 3, 'totalTtc' => 8500]], $sales->summaries([$sale]));
    }

    public function testDuplicateLineShowsRecorderMessage(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);

        $this->submit($client, $this->url($helmet, $local), [
            'buyer' => 'M. Petit',
            'lines' => [
                0 => ['quantity' => '1', 'finalPrice' => '10'],
                1 => ['stock' => $helmet->getId().'-worn', 'quantity' => '1', 'finalPrice' => '10'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('« CASQUE-1 M » (Usé) figure deux fois : augmentez la quantité.', $this->text($client));
        self::assertSame(0, $this->saleCount($client));
    }

    public function testNewStockCannotBeChosen(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);

        $this->submit($client, $this->url($helmet, $local), [
            'buyer' => 'M. Petit',
            'lines' => [0 => ['stock' => $helmet->getId().'-new', 'quantity' => '1', 'finalPrice' => '10']],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->saleCount($client));
    }

    public function testBlankBuyerIsRejected(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);

        $this->submit($client, $this->url($helmet, $local), ['buyer' => '  ', 'lines' => [0 => ['discount' => '80']]]);

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

    public function testPreviewShowsUnitPricesAndTotalsWithoutRecording(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);

        $this->submit($client, $this->url($helmet, $local), ['lines' => [0 => ['quantity' => '2', 'discount' => '80']]], 'Calculer');

        self::assertResponseStatusCodeSame(200);
        $text = $this->text($client);
        self::assertStringContainsString('Prix public : 100,00 €', $text);
        self::assertStringContainsString('Prix TTC : 20,00 €', $text);
        self::assertStringContainsString('Prix HT : 16,67 €', $text);
        self::assertStringContainsString('Total ligne : 40,00 € TTC', $text);
        self::assertStringContainsString('Total : 40,00 € TTC · 33,34 € HT', $text);
        self::assertStringNotContainsString('Indiquez l\'acheteur', $text);
        self::assertSame($helmet->getId().'-worn', $client->getCrawler()->filter('select[name="sale[lines][0][stock]"] option[selected]')->attr('value'));
        self::assertSame(0, $this->saleCount($client));
    }

    public function testPreviewShowsPricingErrorOnTheLine(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client, null);

        $this->submit($client, $this->url($helmet, $local), ['lines' => [0 => ['discount' => '80']]], 'Calculer');

        self::assertResponseStatusCodeSame(200);
        $text = $this->text($client);
        self::assertStringContainsString('Saisissez le prix de vente de « CASQUE-1 M »', $text);
        self::assertStringNotContainsString('Total :', $text);
        self::assertSame(0, $this->saleCount($client));
    }

    public function testDiscountOverriddenByFinalPriceIsFlagged(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $values = ['buyer' => 'M. Petit', 'lines' => [0 => ['discount' => '80', 'finalPrice' => '45']]];

        $this->submit($client, $this->url($helmet, $local), $values, 'Calculer');
        self::assertStringContainsString('Réduction : 80 % (non appliquée)', $this->text($client));
        self::assertStringContainsString('Prix TTC : 45,00 €', $this->text($client));

        $this->submit($client, $this->url($helmet, $local), $values);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('80 % (non appliquée)', $this->text($client));
    }

    public function testVariantQueryWithoutLocationPicksItsSecondHandLocation(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        StockFixtures::location($this->em($client), 'Vestiaire');

        $crawler = $client->request('GET', '/ventes/new?variant='.$helmet->getId().'&etat=good');

        self::assertResponseIsSuccessful();
        self::assertSame((string) $local->getId(), $crawler->filter('select[name="sale[location]"] option[selected]')->attr('value'));
        self::assertSame($helmet->getId().'-good', $crawler->filter('select[name="sale[lines][0][stock]"] option[selected]')->attr('value'));

        // État neuf demandé : non vendable, ligne vide.
        $crawler = $client->request('GET', $this->url($helmet, $local, StockCondition::New));
        self::assertCount(0, $crawler->filter('select[name="sale[lines][0][stock]"] option[selected]:not([value=""])'));
    }

    public function testSaleShowNamesDeletedPlayerWithoutLink(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $em = $this->em($client);
        $player = PlayerFixtures::player($em, 'Martin', 'Léo');
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $sale = $recorder->recordSale(new StockSale($local, 'Mme Dupont', [new StockSaleLine($helmet, StockCondition::Worn, 1, 8000, null)], $player))->getSale();
        self::assertNotNull($sale);
        $em->remove($player);
        $em->flush();
        $em->clear();

        $client->request('GET', '/ventes/'.$sale->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('JoueurLéo Martin (supprimé)', $client->getCrawler()->filter('dl.product-meta')->text());
        self::assertCount(0, $client->getCrawler()->filter('dl.product-meta a'));
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
