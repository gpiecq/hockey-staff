<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

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

final class VariantSheetTest extends WebTestCase
{
    private function sheet(KernelBrowser $client): Variant
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $recorder = $client->getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);

        $local = StockFixtures::location($em, 'Local');
        $helmet = StockScenario::variant($em, TrackingMode::Serialized, 'CASQUE-1', 'Casque Re-Akt');
        $recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $local, [
            new StockEntryLine($helmet, 4, 15000, ItemCondition::New),
            new StockEntryLine($helmet, 1, 15000, ItemCondition::Worn),
        ]));
        $recorder->recordLoan(new StockLoan($local, PlayerFixtures::player($em, 'Martin', 'Léo'), $helmet, ItemCondition::New));

        return $helmet;
    }

    private function login(KernelBrowser $client, UserRole $role): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $client->loginUser(UserFactory::create($em, $role, 'u@example.test'));
    }

    public function testSheetShowsStockByConditionAndHolders(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Stock);
        $variant = $this->sheet($client);

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        self::assertResponseIsSuccessful();
        $text = str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
        self::assertStringContainsString('Neuf 3', $text);
        self::assertStringContainsString('Usé 1', $text);
        self::assertStringContainsString('Léo Martin', $text);
        foreach (['Prêter', 'Retour', 'Donner', 'Sortie'] as $label) {
            self::assertGreaterThan(0, $client->getCrawler()->selectLink($label)->count(), $label);
        }
    }

    public function testViewerSeesNoActionButton(): void
    {
        $client = static::createClient();
        $this->login($client, UserRole::Viewer);
        $variant = $this->sheet($client);

        $client->request('GET', '/stock/variantes/'.$variant->getId());

        self::assertResponseIsSuccessful();
        foreach (['Prêter', 'Retour', 'Donner', 'Sortie'] as $label) {
            self::assertSame(0, $client->getCrawler()->selectLink($label)->count(), $label);
        }
    }
}
