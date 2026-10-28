<?php

declare(strict_types=1);

namespace App\Tests\Functional\Player;

use App\Entity\Location;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\UserRole;
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

    /** @return array{Variant, Location} un casque (3 Neuf à 150 €) au Local */
    private function helmet(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-1', 'Casque Re-Akt');
        StockFixtures::level($em, $helmet, $local, 3, 15000);

        return [$helmet, $local];
    }

    public function testSheetListsHoldingsWithQuantityAndLoanDate(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        [$helmet, $local] = $this->helmet($client);
        $player = PlayerFixtures::player($this->em($client), 'Martin', 'Léo');
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, StockCondition::New, 2));

        $crawler = $client->request('GET', '/joueurs/'.$player->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Matériel détenu', $crawler->filter('body')->text());
        $rows = $crawler->filter('table.holdings tbody tr');
        self::assertCount(1, $rows);
        $row = str_replace("\u{00A0}", ' ', $rows->text());
        self::assertStringContainsString('Casque Re-Akt · M', $row);
        self::assertSame('2', trim($rows->filter('td[data-label="Quantité"]')->text()));
        self::assertStringContainsString('300,00 €', $row);
        self::assertStringContainsString((new \DateTimeImmutable())->format('d/m/Y'), $row);
        self::assertCount(1, $rows->filter('a[href="/stock/variantes/'.$helmet->getId().'"]'));
        self::assertCount(1, $rows->filter('a[href="/stock/variantes/'.$helmet->getId().'/retour?joueur='.$player->getId().'"]'));
    }

    public function testSheetShowsRosterAndHistory(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $em = $this->em($client);
        $player = PlayerFixtures::player($em, 'Martin', 'Léo');
        PlayerFixtures::rosterEntry($em, PlayerFixtures::team($em, 'U15'), PlayerFixtures::season($em, 2026), $player, 9);
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, StockCondition::New, 1));
        $this->recorder($client)->recordReturn(new StockReturn($player, $helmet, 1, $local, StockCondition::Good));

        $client->request('GET', '/joueurs/'.$player->getId());

        $text = $client->getCrawler()->filter('body')->text();
        self::assertStringContainsString('Prêt', $text);
        self::assertStringContainsString('Retour de prêt', $text);
        self::assertStringContainsString('U15', $text);
        self::assertStringContainsString('2026-2027', $text);
        self::assertStringContainsString('Aucun matériel détenu.', $text);
        self::assertCount(0, $client->getCrawler()->filter('a[href*="/retour?joueur="]'));
    }

    public function testViewerSeesNoReturnLink(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $player = PlayerFixtures::player($this->em($client), 'Martin', 'Léo');
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, StockCondition::New, 1));

        $crawler = $client->request('GET', '/joueurs/'.$player->getId());

        self::assertCount(0, $crawler->filter('a[href*="/retour?joueur="]'));
        self::assertStringContainsString('Casque Re-Akt', $crawler->filter('table.holdings')->text());
    }

    public function testPlayerWithHoldingCannotBeDeleted(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        [$helmet, $local] = $this->helmet($client);
        $player = PlayerFixtures::player($this->em($client), 'Martin', 'Léo');
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, StockCondition::New, 1));

        $crawler = $client->request('GET', '/joueurs/'.$player->getId().'/delete');
        $client->submit($crawler->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/joueurs');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Ce joueur détient encore du matériel prêté : enregistrez d\'abord le retour.');
        self::assertNull($this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM player WHERE id = ?', [$player->getId()]));
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
