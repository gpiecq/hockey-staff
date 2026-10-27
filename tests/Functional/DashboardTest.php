<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Season;
use App\Enum\UserRole;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function login(UserRole $role): void
    {
        $this->client->loginUser(UserFactory::create($this->em(), $role, 'u@example.test'));
    }

    public function testNoCurrentSeasonMessage(): void
    {
        $this->login(UserRole::Viewer);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('main', 'Aucune saison courante');
    }

    public function testCurrentSeasonAndCountsAreShown(): void
    {
        $em = $this->em();
        $season = new Season('2026-2027', new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-30'));
        $season->setCurrent(true);
        $em->persist($season);
        $supplier = ImportFixtures::supplierWithMapping($em);
        $p1 = ImportFixtures::product($em, $supplier, 'R1');
        $p2 = ImportFixtures::product($em, $supplier, 'R2');
        ImportFixtures::variant($em, $p1, '688698508062', 'M');
        ImportFixtures::variant($em, $p1, '688698508079', 'L');
        ImportFixtures::variant($em, $p2, '688698508086', 'S', active: false);
        $this->login(UserRole::Viewer);

        $crawler = $this->client->request('GET', '/');

        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('2026-2027', $text);
        self::assertStringNotContainsString('Aucune saison courante', $text);
        self::assertSame('2', trim($crawler->filter('[data-stat="products"] .stat-value')->text()));
        self::assertSame('2', trim($crawler->filter('[data-stat="variants"] .stat-value')->text()));
    }

    public function testVariantsOfSoftDeletedProductsAreNotCounted(): void
    {
        $em = $this->em();
        $season = new Season('2026-2027', new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-30'));
        $season->setCurrent(true);
        $em->persist($season);
        $supplier = ImportFixtures::supplierWithMapping($em);
        $kept = ImportFixtures::product($em, $supplier, 'R1');
        $deleted = ImportFixtures::product($em, $supplier, 'R2');
        ImportFixtures::variant($em, $kept, '688698508062', 'M');
        ImportFixtures::variant($em, $deleted, '688698508079', 'L');
        ImportFixtures::variant($em, $deleted, '688698508086', 'S');
        $em->remove($deleted);
        $em->flush();
        $this->login(UserRole::Viewer);

        $crawler = $this->client->request('GET', '/');

        self::assertSame('1', trim($crawler->filter('[data-stat="products"] .stat-value')->text()));
        self::assertSame('1', trim($crawler->filter('[data-stat="variants"] .stat-value')->text()));
    }

    public function testViewerSeesSearchShortcutButNoImportBlock(): void
    {
        $this->login(UserRole::Viewer);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('main a[href="/catalogue"]');
        self::assertStringContainsString('Rechercher un produit', $crawler->filter('main a[href="/catalogue"]')->text());
        self::assertSelectorNotExists('main a[href="/imports/new"]');
        self::assertStringNotContainsString('Dernier import', $crawler->filter('main')->text());
    }

    public function testManagerSeesLastImportAndNewImportShortcut(): void
    {
        $em = $this->em();
        $run = ImportFixtures::run($em, ImportFixtures::supplierWithMapping($em), ImportFixtures::season($em), []);
        $this->login(UserRole::Manager);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('main a[href="/imports/new"]');
        self::assertStringContainsString('Nouvel import', $crawler->filter('main a[href="/imports/new"]')->text());
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Dernier import', $text);
        self::assertStringContainsString('En attente', $text);
        self::assertStringContainsString($run->getCreatedAt()?->format('d/m/Y') ?? '', $text);
        self::assertSelectorExists('main a[href="/imports/'.$run->getId().'"]');
    }

    public function testLastImportSkipsCancelledRuns(): void
    {
        $em = $this->em();
        $supplier = ImportFixtures::supplierWithMapping($em);
        $season = ImportFixtures::season($em);
        $kept = ImportFixtures::run($em, $supplier, $season, []);
        $cancelled = ImportFixtures::run($em, $supplier, $season, [])->markCancelled();
        $em->flush();
        $this->login(UserRole::Manager);

        $this->client->request('GET', '/');

        self::assertSelectorExists('main a[href="/imports/'.$kept->getId().'"]');
        self::assertSelectorNotExists('main a[href="/imports/'.$cancelled->getId().'"]');
        self::assertSelectorTextNotContains('main', 'Annulé');
    }

    public function testManagerWithOnlyCancelledImportsSeesNoneYet(): void
    {
        $em = $this->em();
        ImportFixtures::run($em, ImportFixtures::supplierWithMapping($em), ImportFixtures::season($em), [])->markCancelled();
        $em->flush();
        $this->login(UserRole::Manager);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('main', 'Aucun import');
    }

    public function testManagerWithoutImportSeesNoneYet(): void
    {
        $this->login(UserRole::Manager);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('main', 'Aucun import');
    }
}
