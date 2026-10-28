<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\StockOperation;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\UserRole;
use App\Stock\Input\StockGift;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockReturn;
use App\Stock\Input\StockTransfer;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OperationControllerTest extends WebTestCase
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

    private function viewer(KernelBrowser $client): void
    {
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Viewer, 'v@example.test'));
    }

    public function testListIsNewestFirstAndPaginated(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        for ($i = 1; $i <= 31; ++$i) {
            StockScenario::receive($this->recorder($client), $variant, $a, 1, 100 + $i);
        }

        $client->request('GET', '/stock/operations');
        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('table.operations tbody tr');
        self::assertCount(30, $rows);
        $newest = $em->getRepository(StockOperation::class)->findOneBy([], ['id' => 'DESC']);
        self::assertNotNull($newest);
        self::assertStringContainsString('/stock/operations/'.$newest->getId(), (string) $rows->first()->filter('a')->attr('href'));

        $client->request('GET', '/stock/operations?page=2');
        self::assertCount(1, $client->getCrawler()->filter('table.operations tbody tr'));
    }

    public function testFilterByTypeAndLocation(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $b = StockFixtures::location($em, 'Local B');
        $variant = StockScenario::variant($em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        StockScenario::receive($this->recorder($client), $variant, $a, 5, 100);
        $this->recorder($client)->recordTransfer(new StockTransfer($a, $b, [new StockLine($variant, 2)]));

        $client->request('GET', '/stock/operations?type=transfer&lieu='.$b->getId());

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('table.operations tbody tr');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Transfert', $rows->text());

        $client->request('GET', '/stock/operations?lieu='.$a->getId());
        self::assertCount(2, $client->getCrawler()->filter('table.operations tbody tr'));
        $client->request('GET', '/stock/operations?type=purchase_in&lieu='.$b->getId());
        self::assertCount(0, $client->getCrawler()->filter('table.operations tbody tr'));
    }

    public function testFilterByVariant(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $v1 = StockScenario::variant($em, ProductKind::Consumable, 'REF-1', 'Ruban');
        $v2 = StockScenario::variant($em, ProductKind::Consumable, 'REF-2', 'Tape');
        StockScenario::receive($this->recorder($client), $v1, $a, 5, 100);
        StockScenario::receive($this->recorder($client), $v2, $a, 5, 100);
        StockScenario::receive($this->recorder($client), $v2, $a, 3, 100);

        $client->request('GET', '/stock/operations?variante='.$v1->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('table.operations tbody tr'));

        $client->request('GET', '/stock/operations?variante='.$v2->getId());
        self::assertCount(2, $client->getCrawler()->filter('table.operations tbody tr'));
    }

    public function testShowListsMovements(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, ProductKind::Equipment, 'REF-S', 'Casque');
        StockScenario::receive($this->recorder($client), $variant, $a, 2, 8000);
        $operation = $em->getRepository(StockOperation::class)->findOneBy([]);
        self::assertNotNull($operation);

        $client->request('GET', '/stock/operations/'.$operation->getId());

        self::assertResponseIsSuccessful();
        $page = str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
        self::assertStringNotContainsString('HS-', $page);
        self::assertStringContainsString('80,00 €', $page);
        self::assertStringContainsString('Local A', $page);
    }

    public function testOperationShowsArchivedVariant(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        StockScenario::receive($this->recorder($client), $variant, $a, 2, 500);
        $variant->archive();
        $em->flush();
        $operation = $em->getRepository(StockOperation::class)->findOneBy([]);
        self::assertNotNull($operation);

        $client->request('GET', '/stock/operations/'.$operation->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Archivée', $client->getCrawler()->filter('body')->text());
    }

    public function testNoEditOrDeleteRoutes(): void
    {
        $client = static::createClient();
        $client->loginUser(UserFactory::create($this->em($client), UserRole::Manager, 'm@example.test'));
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        StockScenario::receive($this->recorder($client), $variant, $a, 2, 500);
        $operation = $em->getRepository(StockOperation::class)->findOneBy([]);
        self::assertNotNull($operation);

        $client->request('POST', '/stock/operations/'.$operation->getId().'/delete');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/stock/operations/'.$operation->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDateRangeEndIsInclusive(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        StockScenario::receive($this->recorder($client), $variant, $a, 1, 100);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');

        $client->request('GET', '/stock/operations?du='.$today.'&au='.$today);
        self::assertCount(1, $client->getCrawler()->filter('table.operations tbody tr'));
        $client->request('GET', '/stock/operations?au='.$yesterday);
        self::assertCount(0, $client->getCrawler()->filter('table.operations tbody tr'));
    }

    /** @return array{StockOperation, StockOperation} prêt au joueur, puis don à l'équipe */
    private function loanAndGift(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, ProductKind::Equipment, 'REF-S', 'Casque');
        StockScenario::receive($this->recorder($client), $variant, $a, 3, 8000, StockCondition::Worn);
        $player = PlayerFixtures::player($em, 'Durand', 'Hugo');
        $team = PlayerFixtures::team($em, 'U17');
        $loan = $this->recorder($client)->recordLoan(new StockLoan($a, $player, $variant, StockCondition::Worn, 2));
        $gift = $this->recorder($client)->recordGift(new StockGift($a, $variant, 1, StockCondition::Worn, null, $team));

        return [$loan, $gift];
    }

    private function page(KernelBrowser $client, StockOperation $operation): string
    {
        $client->request('GET', '/stock/operations/'.$operation->getId());
        self::assertResponseIsSuccessful();

        return str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
    }

    public function testOperationShowsLoanPlayerAndGiftTeam(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        [$loan, $gift] = $this->loanAndGift($client);

        $page = $this->page($client, $loan);
        self::assertSelectorTextContains('dl.product-meta', 'Joueur');
        self::assertStringContainsString('Hugo', $page);
        self::assertStringContainsString('Durand', $page);
        self::assertStringContainsString('Casque', $page);

        $this->page($client, $gift);
        self::assertSelectorTextContains('dl.product-meta', 'Équipe');
        self::assertSelectorTextContains('dl.product-meta', 'U17');
    }

    public function testHistoryStaysReadableAfterPlayerAndTeamAreDeleted(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        [$loan, $gift] = $this->loanAndGift($client);
        $em = $this->em($client);
        $player = $loan->getPlayer();
        $team = $gift->getTeam();
        self::assertNotNull($player);
        self::assertNotNull($team);
        $movement = $loan->getMovements()->first();
        self::assertNotFalse($movement);
        $source = $loan->getSource();
        self::assertNotNull($source);
        // Joueur soldé (aucune détention) : il peut être supprimé.
        $this->recorder($client)->recordReturn(new StockReturn($player, $movement->getVariant(), 2, $source, StockCondition::Worn));
        $em->remove($player);
        $em->remove($team);
        $em->flush();
        $em->clear();

        $page = $this->page($client, $loan);
        self::assertStringContainsString('Durand', $page);
        self::assertStringContainsString('(supprimé)', $page);
        self::assertSelectorTextContains('dl.product-meta', 'Joueur');
        $this->page($client, $gift);
        self::assertSelectorTextContains('dl.product-meta', 'Équipe');
        self::assertSelectorTextContains('dl.product-meta', 'U17');

        $client->request('GET', '/stock/operations');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $client->getCrawler()->filter('table.operations tbody tr'));
    }
}
