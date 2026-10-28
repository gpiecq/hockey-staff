<?php

declare(strict_types=1);

namespace App\Tests\Functional\Player;

use App\Entity\RosterEntry;
use App\Entity\TeamSeason;
use App\Enum\UserRole;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TeamRosterTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(KernelBrowser $client, UserRole $role = UserRole::Manager): void
    {
        $client->loginUser(UserFactory::create($this->em($client), $role, 'u@example.test'));
    }

    private function addToRoster(KernelBrowser $client, int $teamId, int $playerId, string $number): void
    {
        $crawler = $client->request('GET', '/equipes/'.$teamId.'/effectif');
        $form = $crawler->selectButton('Ajouter')->form();
        $form->disableValidation();
        $form['roster_entry[player]'] = (string) $playerId;
        $form['roster_entry[number]'] = $number;
        $client->submit($form);
    }

    public function testManagerCreatesTeamAndSeesItInNavigation(): void
    {
        $client = static::createClient();
        $this->login($client);

        $crawler = $client->request('GET', '/equipes/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['team[name]' => 'U15']));

        self::assertResponseRedirects('/equipes');
        $client->followRedirect();
        self::assertSelectorTextContains('tbody', 'U15');
        self::assertSelectorExists('.sidebar a[href="/equipes"]');
    }

    public function testManagerBuildsRosterForCurrentSeason(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        PlayerFixtures::season($em, 2026, true);
        $team = PlayerFixtures::team($em, 'U15');
        $leo = PlayerFixtures::player($em, 'Martin', 'Léo');
        $hugo = PlayerFixtures::player($em, 'Durand', 'Hugo');

        $this->addToRoster($client, (int) $team->getId(), (int) $leo->getId(), '1');
        self::assertResponseRedirects();
        $this->addToRoster($client, (int) $team->getId(), (int) $hugo->getId(), '6');
        self::assertResponseRedirects();

        $client->request('GET', '/equipes/'.$team->getId().'/effectif');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Léo Martin');
        self::assertSelectorTextContains('tbody', 'Hugo Durand');
        self::assertSelectorTextContains('tbody', '1');
        self::assertSelectorTextContains('tbody', '6');
    }

    public function testSamePlayerTwiceIsRejected(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        PlayerFixtures::season($em, 2026, true);
        $team = PlayerFixtures::team($em);
        $player = PlayerFixtures::player($em);

        $this->addToRoster($client, (int) $team->getId(), (int) $player->getId(), '1');
        $this->addToRoster($client, (int) $team->getId(), (int) $player->getId(), '2');
        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Ce joueur est déjà dans l\'effectif.');
        self::assertSame(1, $em->getRepository(RosterEntry::class)->count([]));
    }

    public function testNumberOutOfRangeIsRejected(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        PlayerFixtures::season($em, 2026, true);
        $team = PlayerFixtures::team($em);
        $player = PlayerFixtures::player($em);

        $this->addToRoster($client, (int) $team->getId(), (int) $player->getId(), '100');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $em->getRepository(RosterEntry::class)->count([]));
    }

    public function testRemoveFromRosterDeletesTheEntry(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        $season = PlayerFixtures::season($em, 2026, true);
        $team = PlayerFixtures::team($em);
        PlayerFixtures::rosterEntry($em, $team, $season, PlayerFixtures::player($em), 4);

        $crawler = $client->request('GET', '/equipes/'.$team->getId().'/effectif');
        $client->submit($crawler->selectButton('Retirer')->form());

        self::assertResponseRedirects();
        self::assertSame(0, $em->getRepository(RosterEntry::class)->count([]));
    }

    public function testCopyFromPreviousSeason(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        $previous = PlayerFixtures::season($em, 2025);
        $current = PlayerFixtures::season($em, 2026, true);
        $team = PlayerFixtures::team($em);
        $a = PlayerFixtures::player($em, 'Aubert', 'Alex');
        $b = PlayerFixtures::player($em, 'Blanc', 'Bastien');
        $c = PlayerFixtures::player($em, 'Cler', 'Carl');
        PlayerFixtures::rosterEntry($em, $team, $previous, $a, 1);
        PlayerFixtures::rosterEntry($em, $team, $previous, $b, 6);
        PlayerFixtures::rosterEntry($em, $team, $previous, $c, 7);
        PlayerFixtures::rosterEntry($em, $team, $current, $b, 9);
        $em->remove($c);
        $em->flush();

        $crawler = $client->request('GET', '/equipes/'.$team->getId().'/effectif');
        $client->submit($crawler->selectButton('Reprendre la saison précédente')->form());
        self::assertResponseRedirects();
        $client->followRedirect();

        self::assertSelectorTextContains('body', '1 joueur(s) repris de la saison précédente.');
        self::assertSelectorTextContains('tbody', 'Alex Aubert');
        self::assertSelectorTextContains('tbody', 'Bastien Blanc');
        self::assertSelectorTextNotContains('tbody', 'Carl Cler');
        $numbers = [];
        foreach ($em->getRepository(RosterEntry::class)->findBy(['teamSeason' => $em->getRepository(TeamSeason::class)->findOneBy(['team' => $team, 'season' => $current])]) as $entry) {
            $numbers[$entry->getPlayer()->getFullName()] = $entry->getNumber();
        }
        ksort($numbers);
        self::assertSame(['Alex Aubert' => 1, 'Bastien Blanc' => 9], $numbers);
    }

    public function testCopyWithoutPreviousSeasonAddsNothing(): void
    {
        $client = static::createClient();
        $this->login($client);
        $em = $this->em($client);
        PlayerFixtures::season($em, 2026, true);
        $team = PlayerFixtures::team($em);

        $crawler = $client->request('GET', '/equipes/'.$team->getId().'/effectif');
        $client->submit($crawler->selectButton('Reprendre la saison précédente')->form());
        $client->followRedirect();

        self::assertSelectorTextContains('body', '0 joueur(s) repris de la saison précédente.');
        self::assertSame(0, $em->getRepository(RosterEntry::class)->count([]));
    }

    public function testPlayerListFiltersByTeamSeason(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        $em = $this->em($client);
        $old = PlayerFixtures::season($em, 2025);
        $season = PlayerFixtures::season($em, 2026, true);
        $team = PlayerFixtures::team($em, 'U15');
        $other = PlayerFixtures::team($em, 'U18');
        PlayerFixtures::rosterEntry($em, $team, $season, PlayerFixtures::player($em, 'Martin', 'Léo'), 12);
        PlayerFixtures::rosterEntry($em, $team, $old, PlayerFixtures::player($em, 'Ancien', 'Paul'), 3);
        PlayerFixtures::rosterEntry($em, $other, $season, PlayerFixtures::player($em, 'Autre', 'Omar'), 5);
        PlayerFixtures::player($em, 'Libre', 'Jo');

        $client->request('GET', '/joueurs?equipe='.$team->getId().'&saison='.$season->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Léo Martin');
        self::assertSelectorTextContains('thead', 'N°');
        self::assertSelectorTextContains('tbody', '12');
        self::assertSelectorTextNotContains('tbody', 'Paul Ancien');
        self::assertSelectorTextNotContains('tbody', 'Omar Autre');
        self::assertSelectorTextNotContains('tbody', 'Jo Libre');
    }

    public function testViewerCannotManageRoster(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        $team = PlayerFixtures::team($this->em($client));

        $client->request('GET', '/equipes');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/equipes/'.$team->getId().'/effectif');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="roster_entry"]');
        $client->request('GET', '/equipes/new');
        self::assertResponseStatusCodeSame(403);
    }
}
