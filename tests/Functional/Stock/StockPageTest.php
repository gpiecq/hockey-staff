<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\SeasonPrice;
use App\Enum\ItemCondition;
use App\Enum\StockOutReason;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\Input\StockGift;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockOut;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockPageTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function recorder(KernelBrowser $client): StockMovementRecorder
    {
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);

        return $recorder;
    }

    private function text(KernelBrowser $client): string
    {
        return str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
    }

    /** @return array{Location, Location} */
    private function scenario(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $b = StockFixtures::location($em, 'Local B');
        $quantity = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Gants vrac');
        $serial = StockScenario::variant($em, TrackingMode::Serialized, 'REF-S', 'Casque série');
        StockScenario::receive($this->recorder($client), $quantity, $a, 3, 1250);
        StockScenario::receive($this->recorder($client), $serial, $b, 1, 8000);

        return [$a, $b];
    }

    public function testStockPageListsQuantitiesAndTotalValue(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        $this->scenario($client);

        $client->request('GET', '/stock');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertStringContainsString('Valeur totale du stock (hors filtres catégorie et recherche) : 117,50 € HT', $this->text($client));
        self::assertStringContainsString('Gants vrac', $this->text($client));
        self::assertStringContainsString('Casque série', $this->text($client));
    }

    public function testLocationFilterRestrictsRowsAndTotal(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        [$a] = $this->scenario($client);

        $client->request('GET', '/stock', ['lieu' => (string) $a->getId()]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertStringContainsString('Valeur totale du stock du lieu (hors filtres catégorie et recherche) : 37,50 € HT', $this->text($client));
        self::assertStringNotContainsString('Casque série', $client->getCrawler()->filter('table.stock-table')->text());
    }

    public function testRemovedItemsAreNotCounted(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        [, $b] = $this->scenario($client);
        $item = $this->em($client)->getRepository(Item::class)->findOneBy([]);
        self::assertNotNull($item);
        $this->recorder($client)->recordOut(new StockOut($b, StockOutReason::Broken, [new StockLine($item->getVariant(), 1, $item)]));

        $client->request('GET', '/stock');

        self::assertCount(1, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertStringNotContainsString('Casque série', $client->getCrawler()->filter('table.stock-table')->text());
        self::assertStringContainsString('Valeur totale du stock (hors filtres catégorie et recherche) : 37,50 € HT', $this->text($client));
    }

    public function testStockValueIgnoresTariffChange(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $client->loginUser(UserFactory::create($em, UserRole::Stock, 'u@example.test'));
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Gants vrac');
        StockScenario::receive($this->recorder($client), $variant, $a, 3, 1250);

        $season = ImportFixtures::season($em);
        $em->persist((new SeasonPrice())->setVariant($variant)->setSeason($season)->setClubPriceTtc(99900));
        $em->flush();

        $client->request('GET', '/stock');

        self::assertStringContainsString('Valeur totale du stock (hors filtres catégorie et recherche) : 37,50 € HT', $this->text($client));
    }

    public function testSearchByEanAndReference(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $v1 = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Gants vrac', '0123456789012');
        $v2 = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Z', 'Bâton vrac', '9876543210987');
        StockScenario::receive($this->recorder($client), $v1, $a, 1, 1000);
        StockScenario::receive($this->recorder($client), $v2, $a, 1, 1000);

        $client->request('GET', '/stock', ['q' => '123456789012']);
        self::assertCount(1, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertStringContainsString('Gants vrac', $client->getCrawler()->filter('table.stock-table')->text());

        $client->request('GET', '/stock', ['q' => 'ref-z']);
        self::assertCount(1, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertStringContainsString('Bâton vrac', $client->getCrawler()->filter('table.stock-table')->text());
    }

    public function testViewerSeesStockButNoActionButtons(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Viewer, 'v@example.test'));
        $this->scenario($client);

        $client->request('GET', '/stock');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertCount(0, $client->getCrawler()->filter('a[href="/stock/sortie"], a[href="/stock/transfert"], a[href^="/stock/entree"]'));
    }

    public function testStockUserSeesActionButtons(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        $this->scenario($client);

        $client->request('GET', '/stock');

        self::assertCount(1, $client->getCrawler()->filter('.page-head a[href="/stock/sortie"]'));
    }

    public function testFullyLoanedVariantStaysListedAndValued(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $client->loginUser(UserFactory::create($em, UserRole::Stock, 'u@example.test'));
        $local = StockFixtures::location($em, 'Local');
        $variant = StockScenario::variant($em, TrackingMode::Serialized, 'REF-S', 'Casque série');
        StockScenario::receive($this->recorder($client), $variant, $local, 1, 5000);
        $this->recorder($client)->recordLoan(new StockLoan($local, PlayerFixtures::player($em), $variant, ItemCondition::New));

        $client->request('GET', '/stock');

        self::assertCount(1, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertSame('1', trim($client->getCrawler()->filter('table.stock-table tbody tr td[data-label="Prêtées"]')->text()));
        self::assertStringContainsString('Valeur totale du stock (hors filtres catégorie et recherche) : 50,00 € HT (pièces prêtées incluses)', $this->text($client));

        $client->request('GET', '/stock', ['lieu' => (string) $local->getId()]);

        self::assertCount(0, $client->getCrawler()->filter('table.stock-table tbody tr'));
        self::assertStringContainsString('Valeur totale du stock du lieu (hors filtres catégorie et recherche) : 0,00 € HT', $this->text($client));
    }

    public function testGiftedItemLeavesValue(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $client->loginUser(UserFactory::create($em, UserRole::Stock, 'u@example.test'));
        $local = StockFixtures::location($em, 'Local');
        $variant = StockScenario::variant($em, TrackingMode::Serialized, 'REF-S', 'Casque série');
        StockScenario::receive($this->recorder($client), $variant, $local, 2, 5000);
        $this->recorder($client)->recordGift(new StockGift($local, $variant, 1, ItemCondition::New, PlayerFixtures::player($em), null));

        $client->request('GET', '/stock');

        self::assertStringContainsString('(hors filtres catégorie et recherche) : 50,00 € HT', $this->text($client));
        self::assertSame('0', trim($client->getCrawler()->filter('table.stock-table tbody tr td[data-label="Prêtées"]')->text()));
    }

    public function testProductPageShowsLoanedCountEvenWhenHolderIsDeleted(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $client->loginUser(UserFactory::create($em, UserRole::Stock, 'u@example.test'));
        $local = StockFixtures::location($em, 'Local');
        $variant = StockScenario::variant($em, TrackingMode::Serialized, 'REF-S', 'Casque série');
        StockScenario::receive($this->recorder($client), $variant, $local, 2, 5000);
        $player = PlayerFixtures::player($em);
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $variant, ItemCondition::New));
        $em->remove($player);
        $em->flush();
        $em->clear();

        $client->request('GET', '/catalogue/'.$variant->getProduct()?->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('dont 1 prêtée', $this->text($client));
    }
}
