<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\StockOperation;
use App\Enum\TrackingMode;
use App\Enum\UserRole;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockTransfer;
use App\Stock\StockMovementRecorder;
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
        $variant = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Ruban');
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
        $variant = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Ruban');
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
        $v1 = StockScenario::variant($em, TrackingMode::Quantity, 'REF-1', 'Ruban');
        $v2 = StockScenario::variant($em, TrackingMode::Quantity, 'REF-2', 'Tape');
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
        $variant = StockScenario::variant($em, TrackingMode::Serialized, 'REF-S', 'Casque');
        StockScenario::receive($this->recorder($client), $variant, $a, 2, 8000);
        $operation = $em->getRepository(StockOperation::class)->findOneBy([]);
        self::assertNotNull($operation);

        $client->request('GET', '/stock/operations/'.$operation->getId());

        self::assertResponseIsSuccessful();
        $page = str_replace("\u{00A0}", ' ', $client->getCrawler()->filter('body')->text());
        self::assertSame(2, preg_match_all('/HS-[A-Z0-9]+/', $page));
        self::assertStringContainsString('80,00 €', $page);
        self::assertStringContainsString('Local A', $page);
    }

    public function testOperationShowsArchivedVariant(): void
    {
        $client = static::createClient();
        $this->viewer($client);
        $em = $this->em($client);
        $a = StockFixtures::location($em, 'Local A');
        $variant = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Ruban');
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
        $variant = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Ruban');
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
        $variant = StockScenario::variant($em, TrackingMode::Quantity, 'REF-Q', 'Ruban');
        StockScenario::receive($this->recorder($client), $variant, $a, 1, 100);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');

        $client->request('GET', '/stock/operations?du='.$today.'&au='.$today);
        self::assertCount(1, $client->getCrawler()->filter('table.operations tbody tr'));
        $client->request('GET', '/stock/operations?au='.$yesterday);
        self::assertCount(0, $client->getCrawler()->filter('table.operations tbody tr'));
    }
}
