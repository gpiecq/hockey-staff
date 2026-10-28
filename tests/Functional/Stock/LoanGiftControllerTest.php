<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\UserRole;
use App\Stock\Input\StockLoan;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoanGiftControllerTest extends WebTestCase
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

    private function login(KernelBrowser $client, UserRole $role = UserRole::Stock): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, 'u@example.test'));
    }

    /** @return array{Variant, Location, Player} un casque (3 Neuf) au Local, seul lieu actif, et un joueur */
    private function helmet(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-1', 'Casque Re-Akt');
        StockFixtures::level($em, $helmet, $local, 3, 15000);

        return [$helmet, $local, PlayerFixtures::player($em, 'Martin', 'Léo')];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submit(KernelBrowser $client, string $url, string $button, string $name, array $values): void
    {
        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton($button)->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), [$name => $values]));
    }

    private function heldBy(KernelBrowser $client, Player $player, Variant $variant): int
    {
        $quantity = $this->em($client)->getConnection()->fetchOne('SELECT quantity FROM player_holding WHERE player_id = ? AND variant_id = ?', [$player->getId(), $variant->getId()]);

        return is_numeric($quantity) ? (int) $quantity : 0;
    }

    public function testLoanFromSheet(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $player] = $this->helmet($client);

        $this->submit($client, '/stock/variantes/'.$helmet->getId().'/pret', 'Prêter', 'stock_loan', [
            'location' => (string) $local->getId(), 'player' => (string) $player->getId(), 'condition' => 'new', 'quantity' => '2', 'comment' => '  ',
        ]);

        self::assertResponseRedirects('/stock/variantes/'.$helmet->getId());
        $client->followRedirect();
        self::assertStringContainsString('Prêt enregistré : 2 × M à Léo Martin.', $client->getCrawler()->filter('body')->text());
        self::assertSame(2, $this->heldBy($client, $player, $helmet));
    }

    public function testLoanFormShowsCountsOfSelectedLocation(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);

        $client->request('GET', '/stock/variantes/'.$helmet->getId().'/pret?lieu='.$local->getId());

        self::assertStringContainsString('Neuf (3)', $client->getCrawler()->filter('body')->text());
        self::assertStringContainsString('Bon (0)', $client->getCrawler()->filter('body')->text());
    }

    public function testLoanFormPreselectsSingleActiveLocationAndDisablesEmptyConditions(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        StockFixtures::location($this->em($client), 'Cave', false);

        $crawler = $client->request('GET', '/stock/variantes/'.$helmet->getId().'/pret');

        self::assertResponseIsSuccessful();
        self::assertSame((string) $local->getId(), $crawler->filter('select[name="stock_loan[location]"] option[selected]')->attr('value'));
        self::assertStringContainsString('Neuf (3)', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter('input[name="stock_loan[condition]"][value="new"][disabled]'));
        foreach (['good', 'worn', 'damaged'] as $value) {
            self::assertCount(1, $crawler->filter('input[name="stock_loan[condition]"][value="'.$value.'"][disabled]'), $value);
        }
        self::assertSame('1', $crawler->filter('input[name="stock_loan[quantity]"]')->attr('value'));
        self::assertCount(1, $crawler->filter('.tiles input[name="stock_loan[condition]"][value="new"]'));
    }

    public function testLoanBeyondStockShowsError(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $player] = $this->helmet($client);

        $this->submit($client, '/stock/variantes/'.$helmet->getId().'/pret', 'Prêter', 'stock_loan', [
            'location' => (string) $local->getId(), 'player' => (string) $player->getId(), 'condition' => 'new', 'quantity' => '4',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Stock insuffisant pour « CASQUE-1 M » (Neuf) à « Local » : 3 disponible(s).', $client->getCrawler()->filter('body')->text());
    }

    public function testReturnFromPlayerSheetPreselectsPlayer(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $leo] = $this->helmet($client);
        $hugo = PlayerFixtures::player($this->em($client), 'Durand', 'Hugo');
        $this->recorder($client)->recordLoan(new StockLoan($local, $leo, $helmet, StockCondition::New, 2));
        $this->recorder($client)->recordLoan(new StockLoan($local, $hugo, $helmet, StockCondition::New, 1));

        $crawler = $client->request('GET', '/stock/variantes/'.$helmet->getId().'/retour');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="stock_return[player]"] option[selected]:not([value=""])'));
        self::assertCount(2, $crawler->filter('select[name="stock_return[player]"] option[value]:not([value=""])'));

        $crawler = $client->request('GET', '/stock/variantes/'.$helmet->getId().'/retour?joueur='.$leo->getId());
        self::assertStringContainsString('Léo Martin', $crawler->filter('select[name="stock_return[player]"] option[selected]')->text());
        self::assertSame('1', $crawler->filter('input[name="stock_return[quantity]"]')->attr('value'));
        self::assertSame((string) $local->getId(), $crawler->filter('select[name="stock_return[location]"] option[selected]')->attr('value'));

        $form = $crawler->selectButton('Enregistrer le retour')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), [
            'stock_return' => ['condition' => 'worn'],
        ]));

        self::assertResponseRedirects('/joueurs/'.$leo->getId());
        $client->followRedirect();
        self::assertStringContainsString('Retour enregistré.', $client->getCrawler()->filter('body')->text());
        self::assertSame(1, $this->heldBy($client, $leo, $helmet));
        self::assertSame(1, $this->heldBy($client, $hugo, $helmet));
    }

    public function testReturnPreselectsSingleHolderAndRefusesExcess(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $player] = $this->helmet($client);
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, StockCondition::New, 1));

        $crawler = $client->request('GET', '/stock/variantes/'.$helmet->getId().'/retour');
        self::assertStringContainsString('Léo Martin', $crawler->filter('select[name="stock_return[player]"] option[selected]')->text());

        $form = $crawler->selectButton('Enregistrer le retour')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), [
            'stock_return' => ['condition' => 'good', 'quantity' => '2'],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Léo Martin détient 1 « CASQUE-1 M » : retour de 2 impossible.', $client->getCrawler()->filter('body')->text());
    }

    public function testLoanListShowsHoldingsFilteredByTeam(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        [$helmet, $local, $leo] = $this->helmet($client);
        $em = $this->em($client);
        $hugo = PlayerFixtures::player($em, 'Durand', 'Hugo');
        $team = PlayerFixtures::team($em, 'U15');
        $season = PlayerFixtures::season($em, 2026);
        PlayerFixtures::rosterEntry($em, $team, $season, $leo, 7);
        $this->recorder($client)->recordLoan(new StockLoan($local, $leo, $helmet, StockCondition::New, 2));
        $this->recorder($client)->recordLoan(new StockLoan($local, $hugo, $helmet, StockCondition::New, 1));

        $crawler = $client->request('GET', '/prets');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Hugo Durand', $crawler->filter('tbody tr')->first()->text());

        $crawler = $client->request('GET', '/prets?equipe='.$team->getId().'&saison='.$season->getId());
        $rows = $crawler->filter('tbody tr');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Léo Martin', $rows->text());
        self::assertStringContainsString('Casque Re-Akt · M', $rows->text());
        self::assertSame('2', trim($rows->filter('td[data-label="Quantité"]')->text()));
        self::assertStringContainsString((new \DateTimeImmutable())->format('d/m/Y'), $rows->text());
    }

    public function testGiftToTeam(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        $local = StockFixtures::location($em, 'Local');
        $pucks = StockScenario::variant($em, ProductKind::Consumable, 'PALET-1', 'Palet');
        StockScenario::receive($this->recorder($client), $pucks, $local, 10, 100);
        $team = PlayerFixtures::team($em, 'U15');

        $this->submit($client, '/stock/variantes/'.$pucks->getId().'/don', 'Donner', 'stock_gift', [
            'location' => (string) $local->getId(), 'recipient' => 'team', 'team' => (string) $team->getId(), 'quantity' => '4',
        ]);

        self::assertResponseRedirects('/stock/variantes/'.$pucks->getId());
        $client->followRedirect();
        self::assertStringContainsString('Don enregistré.', $client->getCrawler()->filter('body')->text());
    }

    public function testGiftFormHasRecipientToggleAndQuantity(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local] = $this->helmet($client);
        $pucks = StockScenario::variant($this->em($client), ProductKind::Consumable, 'PALET-1', 'Palet');
        StockFixtures::level($this->em($client), $pucks, $local, 5, 100);

        $crawler = $client->request('GET', '/stock/variantes/'.$helmet->getId().'/don');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-controller~="recipient"]'));
        self::assertCount(1, $crawler->filter('[name="stock_gift[quantity]"]'));
        self::assertGreaterThan(0, $crawler->filter('[name="stock_gift[condition]"]')->count());
        self::assertCount(1, $crawler->filter('[data-recipient-target="player"]'));
        self::assertCount(1, $crawler->filter('[data-recipient-target="team"]'));

        $crawler = $client->request('GET', '/stock/variantes/'.$pucks->getId().'/don');
        self::assertCount(1, $crawler->filter('[name="stock_gift[quantity]"]'));
        self::assertCount(0, $crawler->filter('[name="stock_gift[condition]"]'));
    }

    public function testGiftEquipmentFromSheet(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $player] = $this->helmet($client);

        $this->submit($client, '/stock/variantes/'.$helmet->getId().'/don', 'Donner', 'stock_gift', [
            'location' => (string) $local->getId(), 'recipient' => 'player', 'player' => (string) $player->getId(), 'condition' => 'new', 'quantity' => '2',
        ]);

        self::assertResponseRedirects('/stock/variantes/'.$helmet->getId());
        $quantity = $this->em($client)->getConnection()->fetchOne('SELECT quantity FROM stock_level WHERE variant_id = ?', [$helmet->getId()]);
        self::assertSame(1, is_numeric($quantity) ? (int) $quantity : 0);
    }

    public function testViewerCannotLoan(): void
    {
        $client = static::createClient();
        [$helmet] = $this->helmet($client);
        $this->login($client, UserRole::Viewer);

        $client->request('GET', '/stock/variantes/'.$helmet->getId().'/pret');

        self::assertResponseStatusCodeSame(403);
    }
}
