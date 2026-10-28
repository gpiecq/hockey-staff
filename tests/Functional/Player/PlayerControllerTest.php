<?php

declare(strict_types=1);

namespace App\Tests\Functional\Player;

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

final class PlayerControllerTest extends WebTestCase
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

    public function testManagerCreatesPlayer(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $crawler = $client->request('GET', '/joueurs/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'player[lastName]' => 'Durand',
            'player[firstName]' => 'Hugo',
            'player[position]' => 'goalie',
        ]));

        self::assertResponseRedirects('/joueurs');
        $client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Hugo Durand');
        self::assertSelectorTextContains('tbody', 'Gardien');
    }

    public function testBlankNamesAreRejected(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $crawler = $client->request('GET', '/joueurs/new');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'player[lastName]' => '',
            'player[firstName]' => '',
            'player[position]' => 'forward',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le nom est obligatoire.');
        self::assertSelectorTextContains('body', 'Le prénom est obligatoire.');
    }

    public function testStockUserCannotManagePlayers(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);

        $client->request('GET', '/joueurs/new');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/joueurs');
        self::assertResponseIsSuccessful();
    }

    public function testViewerSeesPlayersLinkInNavigation(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);

        $client->request('GET', '/joueurs');

        self::assertSelectorExists('.sidebar a[href="/joueurs"]');
    }

    public function testSearchIgnoresAccents(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        PlayerFixtures::player($this->em($client), 'Martin', 'Léo');
        PlayerFixtures::player($this->em($client), 'Durand', 'Hugo');

        $client->request('GET', '/joueurs?q=leo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Léo Martin');
        self::assertSelectorTextNotContains('tbody', 'Hugo Durand');
    }

    public function testDeleteIsSoft(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $player = PlayerFixtures::player($this->em($client));

        $crawler = $client->request('GET', '/joueurs/'.$player->getId().'/delete');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/joueurs');
        $client->followRedirect();
        self::assertSelectorNotExists('tbody tr');
        self::assertNotNull($this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM player WHERE id = ?', [$player->getId()]));
    }

    public function testPlayerHoldingLoanedItemCannotBeDeleted(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $em = $this->em($client);
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $player = PlayerFixtures::player($em);
        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-1', 'Casque');
        StockScenario::receive($recorder, $helmet, $local, 1, 15000);
        $recorder->recordLoan(new StockLoan($local, $player, $helmet, StockCondition::New));

        $crawler = $client->request('GET', '/joueurs/'.$player->getId().'/delete');
        $client->submit($crawler->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/joueurs');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Ce joueur détient encore du matériel prêté : enregistrez d\'abord le retour.');
        self::assertNull($this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM player WHERE id = ?', [$player->getId()]));
    }
}
