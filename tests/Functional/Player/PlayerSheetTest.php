<?php

declare(strict_types=1);

namespace App\Tests\Functional\Player;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Enum\StockMovementType;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockReturn;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayerSheetTest extends WebTestCase
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

    private function login(KernelBrowser $client, UserRole $role = UserRole::Viewer): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, 'u@example.test'));
    }

    /** @return array{Variant, Location} un casque (3 Neuf) au Local */
    private function helmet(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, TrackingMode::Serialized, 'CASQUE-1', 'Casque Re-Akt');
        $this->recorder($client)->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $local, [new StockEntryLine($helmet, 3, 15000, ItemCondition::New)]));

        return [$helmet, $local];
    }

    /** @return list<Item> */
    private function items(KernelBrowser $client, Variant $variant): array
    {
        /** @var list<Item> $items */
        $items = $this->em($client)->getRepository(Item::class)->findBy(['variant' => $variant], ['code' => 'ASC']);

        return $items;
    }

    public function testSheetListsHeldItemsWithReturnLink(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        [$helmet, $local] = $this->helmet($client);
        $player = PlayerFixtures::player($this->em($client), 'Martin', 'Léo');
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, ItemCondition::New));
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, ItemCondition::New));

        $crawler = $client->request('GET', '/joueurs/'.$player->getId());

        self::assertResponseIsSuccessful();
        $codes = array_map(static fn (Item $i): string => $i->getCode(), \array_slice($this->items($client, $helmet), 0, 2));
        foreach ($codes as $code) {
            self::assertStringContainsString($code, $crawler->filter('body')->text());
        }
        $links = $crawler->filter('a[href*="/retour?item=HS-"]');
        self::assertCount(2, $links);
        self::assertStringContainsString('/stock/variantes/'.$helmet->getId().'/retour?item=', (string) $links->first()->attr('href'));
    }

    public function testSheetShowsRosterAndHistory(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $em = $this->em($client);
        $player = PlayerFixtures::player($em, 'Martin', 'Léo');
        PlayerFixtures::rosterEntry($em, PlayerFixtures::team($em, 'U15'), PlayerFixtures::season($em, 2026), $player, 9);
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, ItemCondition::New));
        $item = $this->items($client, $helmet)[0];
        $this->recorder($client)->recordReturn(new StockReturn($item, $player, $local, ItemCondition::Good));

        $client->request('GET', '/joueurs/'.$player->getId());

        $text = $client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('Prêt', $text);
        self::assertStringContainsString('Retour de prêt', $text);
        self::assertStringContainsString('U15', $text);
        self::assertStringContainsString('2026-2027', $text);
        self::assertCount(0, $client->getCrawler()->filter('a[href*="/retour?item=HS-"]'));
    }

    public function testLoanListFiltersByTeamSeason(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $em = $this->em($client);
        $leo = PlayerFixtures::player($em, 'Martin', 'Léo');
        $hugo = PlayerFixtures::player($em, 'Durand', 'Hugo');
        $team = PlayerFixtures::team($em, 'U15');
        $season = PlayerFixtures::season($em, 2026);
        PlayerFixtures::rosterEntry($em, $team, $season, $leo, 7);
        $this->recorder($client)->recordLoan(new StockLoan($local, $leo, $helmet, ItemCondition::New));
        $this->recorder($client)->recordLoan(new StockLoan($local, $hugo, $helmet, ItemCondition::New));

        $crawler = $client->request('GET', '/prets');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));

        $crawler = $client->request('GET', '/prets?equipe='.$team->getId().'&saison='.$season->getId());
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Léo Martin', $crawler->filter('tbody')->text());
    }

    public function testViewerSeesNoReturnLink(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $player = PlayerFixtures::player($this->em($client), 'Martin', 'Léo');
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, ItemCondition::New));

        $crawler = $client->request('GET', '/joueurs/'.$player->getId());

        self::assertCount(0, $crawler->filter('a[href*="/retour?item=HS-"]'));
        self::assertStringContainsString('HS-', $crawler->filter('body')->text());
    }

    public function testNavShowsCurrentLoansLink(): void
    {
        $client = static::createClient();
        $this->login($client);

        $crawler = $client->request('GET', '/prets');

        self::assertCount(1, $crawler->filter('nav.sidebar a[href="/prets"]'));
        self::assertStringContainsString('Prêts en cours', $crawler->filter('nav.sidebar')->text());
    }
}
