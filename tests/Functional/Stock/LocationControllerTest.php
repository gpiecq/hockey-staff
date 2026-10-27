<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Location;
use App\Enum\StockMovementType;
use App\Enum\UserRole;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocationControllerTest extends WebTestCase
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

    public function testViewerCannotManageLocations(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);

        $crawler = $client->request('GET', '/lieux');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $crawler->filter('.sidebar a[href="/lieux"]'));
    }

    public function testViewerSidebarHasNoLocationLink(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);

        $client->request('GET', '/');

        self::assertSelectorNotExists('.sidebar a[href="/lieux"]');
    }

    public function testStockRoleCannotManageLocations(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);

        $client->request('GET', '/lieux/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCreatesLocation(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);

        $crawler = $client->request('GET', '/lieux/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Enregistrer')->form([
            'location[name]' => 'Local matériel',
            'location[description]' => 'Sous la tribune',
        ]));

        self::assertResponseRedirects('/lieux');
        $client->followRedirect();
        self::assertSelectorTextContains('tbody', 'Local matériel');
        self::assertSelectorExists('.sidebar a[href="/lieux"]');
    }

    public function testDuplicateNameIsRejected(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        StockFixtures::location($this->em($client), 'Vestiaire');

        $crawler = $client->request('GET', '/lieux/new');
        $client->submit($crawler->selectButton('Enregistrer')->form(['location[name]' => 'Vestiaire']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Ce nom est déjà utilisé.');
    }

    public function testEmptyLocationCanBeDeleted(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $location = StockFixtures::location($this->em($client), 'Vestiaire');

        $client->request('GET', '/lieux');
        $client->clickLink('Supprimer');
        self::assertResponseIsSuccessful();
        $client->submit($client->getCrawler()->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/lieux');
        $client->followRedirect();
        self::assertSelectorNotExists('tbody tr');
        self::assertNotNull($this->em($client)->getConnection()->fetchOne('SELECT deleted_at FROM location WHERE id = ?', [$location->getId()]));
    }

    public function testManagerEditsAndDeactivates(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $location = StockFixtures::location($this->em($client), 'Vestiaire');

        $crawler = $client->request('GET', '/lieux/'.$location->getId().'/edit');
        $client->submit($crawler->selectButton('Enregistrer')->form(['location[name]' => 'Vestiaire A', 'location[active]' => false]));

        self::assertResponseRedirects('/lieux');
        $this->em($client)->clear();
        $reloaded = $this->em($client)->find(Location::class, $location->getId());
        self::assertSame('Vestiaire A', $reloaded?->getName());
        self::assertFalse($reloaded->isActive());
    }

    public function testLocationWithMovementsCannotBeDeleted(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $em = $this->em($client);
        $location = StockFixtures::location($em, 'Vestiaire');
        $variant = ImportFixtures::variant($em, ImportFixtures::product($em, ImportFixtures::supplierWithMapping($em), 'REF-1'), '1234567890123');
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [new StockEntryLine($variant, 1, 100)]));

        $crawler = $client->request('GET', '/lieux/'.$location->getId().'/delete');
        $client->submit($crawler->selectButton('Confirmer la suppression')->form());

        self::assertResponseRedirects('/lieux');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Ce lieu a des mouvements : désactivez-le plutôt.');
        self::assertNull($em->getConnection()->fetchOne('SELECT deleted_at FROM location WHERE id = ?', [$location->getId()]));
    }

    public function testLocationWithStockCannotBeDeactivated(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $em = $this->em($client);
        $location = StockFixtures::location($em, 'Vestiaire');
        $variant = ImportFixtures::variant($em, ImportFixtures::product($em, ImportFixtures::supplierWithMapping($em), 'REF-1'), '1234567890123');
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [new StockEntryLine($variant, 1, 100)]));

        $crawler = $client->request('GET', '/lieux/'.$location->getId().'/edit');
        $client->submit($crawler->selectButton('Enregistrer')->form(['location[active]' => false]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Ce lieu contient du stock : videz-le avant de le désactiver.');
        $em->clear();
        self::assertTrue($em->find(Location::class, $location->getId())?->isActive());
    }
}
