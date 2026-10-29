<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Location;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Enum\UserRole;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockSale;
use App\Stock\Input\StockSaleLine;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VariantSheetTest extends WebTestCase
{
    private function sheet(KernelBrowser $client): Variant
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);

        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-1', 'Casque Re-Akt');
        $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $local, [
            new StockEntryLine($helmet, 4, 15000, StockCondition::New),
        ]));
        $recorder->recordLoan(new StockLoan($local, PlayerFixtures::player($em, 'Martin', 'Léo'), $helmet, StockCondition::New, 1));

        return $helmet;
    }

    private function login(KernelBrowser $client, UserRole $role, string $email = 'u@example.test'): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $client->loginUser(UserFactory::create($em, $role, $email));
    }

    public function testSheetShowsStockByConditionAndHolders(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->sheet($client);
        $this->secondHand($client, $variant);

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        self::assertResponseIsSuccessful();
        $text = str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
        self::assertStringContainsString('Local : Neuf 3 · Usé 2', $text);
        self::assertStringContainsString('Léo Martin', $text);
        foreach (['Prêter', 'Retour', 'Donner', 'Sortie'] as $label) {
            self::assertGreaterThan(0, $client->getCrawler()->selectLink($label)->count(), $label);
        }
    }

    public function testActionsHiddenWithoutStock(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $variant = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-9', 'Casque vide');

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        self::assertResponseIsSuccessful();
        $actions = $client->getCrawler()->filter('.variant-actions');
        foreach (['Prêter', 'Retour', 'Donner', 'Transfert', 'Sortie', 'Vendre'] as $label) {
            self::assertSame(0, $actions->selectLink($label)->count(), $label);
        }
        self::assertSame(1, $actions->selectLink('Achat')->count());
        self::assertSame(1, $actions->selectLink('Dotation')->count());
    }

    public function testEquipmentSheetShowsConditionsHoldersAndActions(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $local = StockFixtures::location($em);
        $helmet = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-2', 'Casque Neuf-Usé');
        StockScenario::receive($recorder, $helmet, $local, 4, 15000);
        StockScenario::receive($recorder, $helmet, $local, 2, 9000, StockCondition::Worn);
        $recorder->recordLoan(new StockLoan($local, PlayerFixtures::player($em, 'Martin', 'Léo'), $helmet, StockCondition::New, 1));

        $client->request('GET', '/stock/variantes/'.$helmet->getId());

        self::assertResponseIsSuccessful();
        $text = str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
        self::assertStringContainsString('Local matériel : Neuf 3 · Usé 2', $text);
        self::assertStringContainsString('Léo Martin', $text);
        $actions = $client->getCrawler()->filter('.variant-actions');
        foreach (['Prêter', 'Retour', 'Donner', 'Sortie', 'Vendre'] as $label) {
            self::assertSame(1, $actions->selectLink($label)->count(), $label);
        }
        self::assertSame('/stock/sortie?variant='.$helmet->getId().'&lieu='.$local->getId(), $actions->selectLink('Sortie')->attr('href'));
    }

    public function testConsumableSheetShowsPlainQuantityWithoutLoan(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $tape = StockScenario::variant($em, ProductKind::Consumable, 'TAPE-1', 'Tape');
        StockScenario::receive($recorder, $tape, StockFixtures::location($em), 5, 300);

        $client->request('GET', '/stock/variantes/'.$tape->getId());

        self::assertResponseIsSuccessful();
        $text = str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
        self::assertStringContainsString('Local matériel : 5', $text);
        self::assertStringContainsString('Consommable', $text);
        self::assertSame(0, $client->getCrawler()->selectLink('Prêter')->count());
        self::assertSame(0, $client->getCrawler()->selectLink('Vendre')->count());
        self::assertSame(1, $client->getCrawler()->selectLink('Donner')->count());
    }

    public function testSecondHandAtInactiveLocationIsNotOfferedForSale(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $closed = StockFixtures::location($em, 'Ancien local');
        $helmet = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-3', 'Casque ancien local');
        StockScenario::receive($recorder, $helmet, $closed, 2, 9000, StockCondition::Worn);
        $closed->setActive(false);
        $em->flush();

        $client->request('GET', '/stock/variantes/'.$helmet->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $client->getCrawler()->selectLink('Vendre')->count());
    }

    /** Occasion en quantité au « Local » : 2 Usé (niveau de stock). */
    private function secondHand(KernelBrowser $client, Variant $variant): Location
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $local = $em->getRepository(Location::class)->findOneBy(['name' => 'Local']);
        \assert($local instanceof Location);
        StockFixtures::level($em, $variant, $local, 2, 15000, StockCondition::Worn);

        return $local;
    }

    public function testSellButtonOnlyForManagerWithSecondHandStock(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Manager);
        $variant = $this->sheet($client);

        // Sans occasion en stock (seulement du neuf) : pas de bouton Vendre.
        $client->request('GET', '/stock/variantes/'.$variant->getId());
        self::assertSame(0, $client->getCrawler()->selectLink('Vendre')->count());

        $local = $this->secondHand($client, $variant);
        $stock = $client;
        $this->login($stock, UserRole::Stock, 's@example.test');
        $stock->request('GET', '/stock/variantes/'.$variant->getId());
        self::assertSame(0, $stock->getCrawler()->selectLink('Vendre')->count());

        $manager = $client;
        $this->login($manager, UserRole::Manager, 'm@example.test');
        $manager->request('GET', '/stock/variantes/'.$variant->getId());
        $link = $manager->getCrawler()->filter('.variant-actions')->selectLink('Vendre');
        self::assertSame(1, $link->count());
        self::assertSame('/ventes/new?variant='.$variant->getId(), $link->attr('href'));

        // Toute l'occasion vendue : le bouton disparaît.
        $em = $manager->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $recorder = $manager->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $local = $em->find(Location::class, $local->getId());
        $sold = $em->find(Variant::class, $variant->getId());
        \assert($local instanceof Location && $sold instanceof Variant);
        $recorder->recordSale(new StockSale($local, 'Mme Dupont', [new StockSaleLine($sold, StockCondition::Worn, 2, null, 1000)]));
        $manager->request('GET', '/stock/variantes/'.$variant->getId());
        self::assertSame(0, $manager->getCrawler()->selectLink('Vendre')->count());
    }

    public function testManagerSeesSecondHandLevelsWithSellLinks(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->sheet($client);
        $local = $this->secondHand($client, $variant);
        $href = '/ventes/new?variant='.$variant->getId().'&etat=worn&lieu='.$local->getId();

        $client->request('GET', '/stock/variantes/'.$variant->getId());
        self::assertCount(0, $client->getCrawler()->filter('a[href="'.$href.'"]'));
        self::assertStringNotContainsString('Occasion en stock', $client->getCrawler()->filter('body')->text());

        $manager = $client;
        $this->login($manager, UserRole::Manager, 'm@example.test');
        $manager->request('GET', '/stock/variantes/'.$variant->getId());
        self::assertStringContainsString('Occasion en stock', $manager->getCrawler()->filter('body')->text());
        $table = $manager->getCrawler()->filter('table.second-hand');
        self::assertCount(1, $table);
        self::assertCount(1, $table->filter('tbody tr'));
        $row = $table->filter('tbody tr');
        self::assertSame('Local', trim($row->filter('td[data-label="Lieu"]')->text()));
        self::assertSame('Usé', trim($row->filter('td[data-label="État"]')->text()));
        self::assertSame('2', trim($row->filter('td[data-label="Quantité"]')->text()));
        self::assertSame('Vendre', trim($table->filter('a[href="'.$href.'"]')->text()));
    }

    public function testEntryActionsAlwaysShownForStockRole(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $variant = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-10', 'Casque sans stock');

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler()->filter('.variant-actions');
        self::assertSame('/stock/entree/achat?variant='.$variant->getId(), $crawler->selectLink('Achat')->attr('href'));
        self::assertSame('/stock/entree/dotation?variant='.$variant->getId(), $crawler->selectLink('Dotation')->attr('href'));
        self::assertSame(0, $crawler->selectLink('Transfert')->count());
        self::assertSame(0, $crawler->selectLink('Sortie')->count());
    }

    public function testTransferAndOutPrefillSingleActiveLocation(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->sheet($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $local = $em->getRepository(Location::class)->findOneBy(['name' => 'Local']);
        \assert($local instanceof Location);

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        $crawler = $client->getCrawler()->filter('.variant-actions');
        self::assertSame('/stock/transfert?variant='.$variant->getId().'&lieu='.$local->getId(), $crawler->selectLink('Transfert')->attr('href'));
        self::assertSame('/stock/sortie?variant='.$variant->getId().'&lieu='.$local->getId(), $crawler->selectLink('Sortie')->attr('href'));
    }

    public function testNoLocationPrefillWhenSeveralActiveLocations(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->sheet($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        StockFixtures::level($em, $variant, StockFixtures::location($em, 'Vestiaire'), 2, 15000, StockCondition::New);

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        $crawler = $client->getCrawler()->filter('.variant-actions');
        self::assertSame('/stock/transfert?variant='.$variant->getId(), $crawler->selectLink('Transfert')->attr('href'));
        self::assertSame('/stock/sortie?variant='.$variant->getId(), $crawler->selectLink('Sortie')->attr('href'));
    }

    public function testNoLocationPrefillWhenOnlyInactiveLocation(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $closed = StockFixtures::location($em, 'Ancien local');
        $variant = StockScenario::variant($em, ProductKind::Equipment, 'CASQUE-11', 'Casque lieu fermé');
        StockFixtures::level($em, $variant, $closed, 2, 15000, StockCondition::New);
        $closed->setActive(false);
        $em->flush();

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        self::assertSame('/stock/sortie?variant='.$variant->getId(), $client->getCrawler()->filter('.variant-actions')->selectLink('Sortie')->attr('href'));
    }

    public function testViewerSeesNoActions(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        $variant = $this->sheet($client);

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        self::assertResponseIsSuccessful();
        foreach (['Achat', 'Dotation', 'Transfert', 'Sortie', 'Prêter'] as $label) {
            self::assertSame(0, $client->getCrawler()->filter('.variant-actions')->selectLink($label)->count(), $label);
        }
    }
}
