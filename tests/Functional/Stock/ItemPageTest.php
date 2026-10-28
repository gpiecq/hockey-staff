<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\Item;
use App\Entity\Location;
use App\Enum\ItemCondition;
use App\Enum\StockOutReason;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\Input\StockGift;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockOut;
use App\Stock\Input\StockTransfer;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ItemPageTest extends WebTestCase
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

    /** @return array{Item, Location, Location} */
    private function serialized(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $b = StockFixtures::location($em, 'Local B');
        $variant = StockScenario::variant($em, TrackingMode::Serialized, 'REF-S', 'Casque série');
        StockScenario::receive($this->recorder($client), $variant, $a, 1, 8000);
        $item = $em->getRepository(Item::class)->findOneBy([]);
        self::assertNotNull($item);

        return [$item, $a, $b];
    }

    public function testItemShowsHistory(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        [$item, $a, $b] = $this->serialized($client);
        $this->recorder($client)->recordTransfer(new StockTransfer($a, $b, [new StockLine($item->getVariant(), 1, $item)]));

        $client->request('GET', '/stock/pieces/'.strtolower($item->getCode()));

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('table.history tbody tr');
        self::assertCount(2, $rows);
        $text = $client->getCrawler()->filter('table.history')->text();
        self::assertStringContainsString('Achat', $text);
        self::assertStringContainsString('Transfert', $text);
        $page = str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
        self::assertStringContainsString($item->getCode(), $page);
        self::assertStringContainsString('80,00 € HT', $page);
        self::assertStringContainsString('Local B', $page);
        self::assertCount(1, $client->getCrawler()->filter('.page-head a[href^="/stock/sortie?item="]'));
        self::assertCount(1, $client->getCrawler()->filter('.page-head a[href^="/stock/transfert?item="]'));
    }

    public function testRemovedItemIsViewableWithoutActions(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        [$item, $a] = $this->serialized($client);
        $this->recorder($client)->recordOut(new StockOut($a, StockOutReason::Broken, [new StockLine($item->getVariant(), 1, $item)]));

        $client->request('GET', '/stock/pieces/'.$item->getCode());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filter('.page-head a[href^="/stock/sortie?item="]'));
        self::assertCount(2, $client->getCrawler()->filter('table.history tbody tr'));
    }

    public function testViewerSeesNoActionButtonsAndUnknownCodeIs404(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Viewer, 'v@example.test'));
        [$item] = $this->serialized($client);

        $client->request('GET', '/stock/pieces/'.$item->getCode());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $client->getCrawler()->filter('.page-head a[href^="/stock/"]'));

        $client->request('GET', '/stock/pieces/HS-999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testProductPageShowsStock(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Viewer, 'v@example.test'));
        [$item] = $this->serialized($client);
        $variant = StockScenario::variant($this->em($client), TrackingMode::Quantity, 'REF-Q', 'Gants vrac');
        $a = $this->em($client)->getRepository(Location::class)->findOneBy(['name' => 'Local A']);
        self::assertNotNull($a);
        StockScenario::receive($this->recorder($client), $variant, $a, 4, 1000);

        $client->request('GET', '/catalogue/'.$item->getVariant()->getProduct()?->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/stock/pieces/'.$item->getCode().'"]');
        self::assertStringContainsString($item->getCode(), $client->getCrawler()->filter('body')->text());

        $client->request('GET', '/catalogue/'.$variant->getProduct()?->getId());
        $text = $client->getCrawler()->filter('table')->text();
        self::assertStringContainsString('Local A', $text);
        self::assertSelectorTextContains('td[data-label="Stock"]', '4');
    }

    public function testLoanedItemShowsHolderAndOperationsShowRecipient(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Stock, 'u@example.test'));
        [$item, $a] = $this->serialized($client);
        $player = PlayerFixtures::player($this->em($client), 'Martin', 'Léo');
        $loan = $this->recorder($client)->recordLoan(new StockLoan($a, $player, $item->getVariant(), ItemCondition::New));
        $team = PlayerFixtures::team($this->em($client), 'U15');
        $pucks = StockScenario::variant($this->em($client), TrackingMode::Quantity, 'PALET', 'Palet');
        StockScenario::receive($this->recorder($client), $pucks, $a, 10, 100);
        $gift = $this->recorder($client)->recordGift(new StockGift($a, $pucks, 2, null, null, $team));

        $client->request('GET', '/stock/pieces/'.$item->getCode());
        self::assertResponseIsSuccessful();
        $meta = $client->getCrawler()->filter('dl.product-meta')->text();
        self::assertStringContainsString('Prêtée', $meta);
        self::assertStringContainsString('DétenteurLéo Martin', $meta);
        self::assertStringContainsString('Joueur : Léo Martin', $client->getCrawler()->filter('table.history')->text());
        self::assertCount(0, $client->getCrawler()->filter('.page-head a[href^="/stock/sortie?item="]'));

        $client->request('GET', '/stock/operations/'.$loan->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('JoueurLéo Martin', $client->getCrawler()->filter('dl.product-meta')->text());

        $client->request('GET', '/stock/operations/'.$gift->getId());
        self::assertStringContainsString('ÉquipeU15', $client->getCrawler()->filter('dl.product-meta')->text());

        $client->request('GET', '/stock/operations');
        $text = $client->getCrawler()->filter('table.operations')->text();
        self::assertStringContainsString('Joueur : Léo Martin', $text);
        self::assertStringContainsString('Équipe : U15', $text);
    }
}
