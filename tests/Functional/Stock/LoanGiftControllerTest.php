<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Location;
use App\Entity\Player;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Enum\StockMovementType;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
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

    /** @return array{Variant, Location, Player} un casque (3 Neuf) au Local et un joueur */
    private function helmet(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, TrackingMode::Serialized, 'CASQUE-1', 'Casque Re-Akt');
        $this->recorder($client)->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $local, [new StockEntryLine($helmet, 3, 15000, ItemCondition::New)]));

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

    public function testLoanFromSheet(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $player] = $this->helmet($client);

        $this->submit($client, '/stock/variantes/'.$helmet->getId().'/pret', 'Prêter', 'stock_loan', [
            'location' => (string) $local->getId(), 'player' => (string) $player->getId(), 'condition' => 'new', 'comment' => '  ',
        ]);

        self::assertResponseRedirects('/stock/variantes/'.$helmet->getId());
        $client->followRedirect();
        self::assertStringContainsString('prêté à Léo Martin', $client->getCrawler()->filter('body')->text());
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

    public function testLoanWithNoItemLeftShowsError(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $player] = $this->helmet($client);

        $this->submit($client, '/stock/variantes/'.$helmet->getId().'/pret', 'Prêter', 'stock_loan', [
            'location' => (string) $local->getId(), 'player' => (string) $player->getId(), 'condition' => 'worn',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Aucune pièce', $client->getCrawler()->filter('body')->text());
    }

    public function testReturnPreselectsSingleLoanedItem(): void
    {
        $client = static::createClient();
        $this->login($client);
        [$helmet, $local, $player] = $this->helmet($client);
        $this->recorder($client)->recordLoan(new StockLoan($local, $player, $helmet, ItemCondition::New));

        $crawler = $client->request('GET', '/stock/variantes/'.$helmet->getId().'/retour');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('HS-', $crawler->filter('select[name="stock_return[item]"] option[selected]')->text());

        $form = $crawler->selectButton('Enregistrer le retour')->form();
        $client->request($form->getMethod(), $form->getUri(), array_replace_recursive($form->getPhpValues(), [
            'stock_return' => ['location' => (string) $local->getId(), 'condition' => 'worn'],
        ]));

        self::assertResponseRedirects('/joueurs/'.$player->getId());
        $client->followRedirect();
        self::assertStringContainsString('Retour enregistré.', $client->getCrawler()->filter('body')->text());
    }

    public function testGiftToTeam(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        $local = StockFixtures::location($em, 'Local');
        $pucks = StockScenario::variant($em, TrackingMode::Quantity, 'PALET-1', 'Palet');
        StockScenario::receive($this->recorder($client), $pucks, $local, 10, 100);
        $team = PlayerFixtures::team($em, 'U15');

        $this->submit($client, '/stock/variantes/'.$pucks->getId().'/don', 'Donner', 'stock_gift', [
            'location' => (string) $local->getId(), 'recipient' => 'team', 'team' => (string) $team->getId(), 'quantity' => '4',
        ]);

        self::assertResponseRedirects('/stock/variantes/'.$pucks->getId());
        $client->followRedirect();
        self::assertStringContainsString('Don enregistré.', $client->getCrawler()->filter('body')->text());
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
