<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Season;
use App\Enum\UserRole;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeasonControllerTest extends WebTestCase
{
    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function addSeason(KernelBrowser $client, string $label, bool $current = false): Season
    {
        [$a, $b] = array_map('intval', explode('-', $label));
        $season = new Season($label, new \DateTimeImmutable("$a-08-01"), new \DateTimeImmutable("$b-07-31"));
        $season->setCurrent($current);
        $this->em($client)->persist($season);
        $this->em($client)->flush();

        return $season;
    }

    public function testViewerSeesListAndMenuButNoActions(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Viewer, 'v@example.test'));
        $this->addSeason($client, '2025-2026');
        $this->addSeason($client, '2026-2027', true);

        $client->request('GET', '/saisons');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/saisons"]');
        self::assertSelectorTextContains('body', '2026-2027');
        self::assertSelectorNotExists('a[href="/saisons/new"]');
        self::assertSelectorNotExists('form[action$="/current"]');
    }

    public function testListSortedByLabelDescending(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Viewer, 'v@example.test'));
        $this->addSeason($client, '2025-2026');
        $this->addSeason($client, '2026-2027');

        $crawler = $client->request('GET', '/saisons');

        $labels = $crawler->filter('tbody tr td:first-child')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['2026-2027', '2025-2026'], array_map(static fn (string $l): string => substr($l, 0, 9), $labels));
    }

    public function testStockCannotCreate(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 's@example.test'));

        $client->request('GET', '/saisons/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCreatesSeasonWithAuthor(): void
    {
        $client = static::createClient();
        $manager = UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test');
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/saisons/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'season[label]' => '2026-2027',
            'season[startsOn]' => '2026-08-01',
            'season[endsOn]' => '2027-07-31',
        ]));

        self::assertResponseRedirects('/saisons');
        $this->em($client)->clear();
        $season = $this->em($client)->getRepository(Season::class)->findOneBy(['label' => '2026-2027']);
        self::assertInstanceOf(Season::class, $season);
        self::assertSame('2026-08-01', $season->getStartsOn()->format('Y-m-d'));
        self::assertSame('2027-07-31', $season->getEndsOn()->format('Y-m-d'));
        self::assertFalse($season->isCurrent());
        self::assertSame($manager->getId(), $season->getCreatedBy()?->getId());
        self::assertSame($manager->getId(), $season->getUpdatedBy()?->getId());
        self::assertNotNull($season->getCreatedAt());
    }

    public function testInvalidLabelRendersErrorWith422(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test'));

        $crawler = $client->request('GET', '/saisons/new');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'season[label]' => '2026-2028',
            'season[startsOn]' => '2026-08-01',
            'season[endsOn]' => '2027-07-31',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-error');
    }

    public function testManagerEditsSeason(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test'));
        $season = $this->addSeason($client, '2026-2027');

        $crawler = $client->request('GET', '/saisons/'.$season->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form(['season[endsOn]' => '2027-06-30']));

        self::assertResponseRedirects('/saisons');
        $this->em($client)->clear();
        $reloaded = $this->em($client)->find(Season::class, $season->getId());
        self::assertSame('2027-06-30', $reloaded?->getEndsOn()->format('Y-m-d'));
    }

    public function testMakeCurrentKeepsASingleCurrentSeason(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test'));
        $this->addSeason($client, '2025-2026', true);
        $second = $this->addSeason($client, '2026-2027');

        $crawler = $client->request('GET', '/saisons');
        $client->submit($crawler->filter('form[action="/saisons/'.$second->getId().'/current"]')->form());

        self::assertResponseRedirects('/saisons');
        $current = $this->em($client)->getConnection()->fetchFirstColumn('SELECT label FROM season WHERE is_current = true');
        self::assertSame(['2026-2027'], $current);
    }

    public function testMakeCurrentRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test'));
        $season = $this->addSeason($client, '2026-2027');

        $client->request('POST', '/saisons/'.$season->getId().'/current', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em($client)->getConnection()->fetchFirstColumn('SELECT label FROM season WHERE is_current = true'));
    }

    public function testStockCannotMakeCurrent(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 's@example.test'));
        $season = $this->addSeason($client, '2026-2027');

        $client->request('POST', '/saisons/'.$season->getId().'/current');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyDateRendersErrorWith422(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test'));

        $crawler = $client->request('GET', '/saisons/new');
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'season[label]' => '2026-2027',
            'season[startsOn]' => '',
            'season[endsOn]' => '2027-07-31',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSoftDeleteFilterIsEnabled(): void
    {
        $client = static::createClient();

        self::assertArrayHasKey('softdeleteable', $this->em($client)->getFilters()->getEnabledFilters());
    }

    public function testMakingTheSameSeasonCurrentTwiceKeepsItCurrent(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test'));
        $season = $this->addSeason($client, '2026-2027');

        $crawler = $client->request('GET', '/saisons');
        $form = $crawler->filter('form[action="/saisons/'.$season->getId().'/current"]')->form();
        $client->submit($form);
        $client->submit($form);

        self::assertResponseRedirects('/saisons');
        $current = $this->em($client)->getConnection()->fetchFirstColumn('SELECT label FROM season WHERE is_current = true');
        self::assertSame(['2026-2027'], $current);
    }
}
