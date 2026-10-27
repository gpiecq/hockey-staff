<?php

declare(strict_types=1);

namespace App\Tests\Functional\Import;

use App\Enum\ImportRunStatus;
use App\Import\ImportDiff;
use App\Import\Message\AnalyzeImportRun;
use App\Import\MessageHandler\AnalyzeImportRunHandler;
use App\Tests\Support\CatalogueSpreadsheetFactory;
use App\Tests\Support\ImportFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AnalyzeImportRunHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AnalyzeImportRunHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $handler = self::getContainer()->get(AnalyzeImportRunHandler::class);
        \assert($em instanceof EntityManagerInterface && $handler instanceof AnalyzeImportRunHandler);
        [$this->em, $this->handler] = [$em, $handler];
    }

    protected function tearDown(): void
    {
        ImportFixtures::cleanup();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function goodRows(): array
    {
        return [[
            'Référence interne' => 'R1', 'Gencode' => '688698508062', 'Désignation' => 'Patin', 'Taille' => 'SR L',
            'PU Public TTC' => '300', 'PU Club TTC' => '250', 'Catégorie' => 'EQUIPEMENT', 'Marque' => 'BAUER',
        ]];
    }

    public function testPendingRunBecomesAnalyzedWithDiff(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $run = ImportFixtures::run($this->em, $supplier, ImportFixtures::season($this->em), $this->goodRows());
        self::assertSame(ImportRunStatus::Pending, $run->getStatus());

        ($this->handler)(new AnalyzeImportRun((int) $run->getId()));

        $this->em->clear();
        $reloaded = $this->em->find($run::class, $run->getId());
        self::assertNotNull($reloaded);
        self::assertSame(ImportRunStatus::Analyzed, $reloaded->getStatus());
        self::assertNotNull($reloaded->getAnalyzedAt());
        self::assertNull($reloaded->getError());
        $diff = ImportDiff::fromArray($reloaded->getDiff() ?? []);
        self::assertCount(1, $diff->newProducts);
        self::assertCount(1, $diff->newVariants);
    }

    public function testMissingRequiredHeaderFailsTheRunWithReadableMessage(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $headers = array_values(array_diff(CatalogueSpreadsheetFactory::BAUER_HEADERS, ['Désignation']));
        $run = ImportFixtures::run($this->em, $supplier, ImportFixtures::season($this->em), $this->goodRows(), $headers);

        ($this->handler)(new AnalyzeImportRun((int) $run->getId()));

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertStringContainsString('Désignation', (string) $run->getError());
        self::assertNull($run->getDiff());
    }

    public function testUnreadableFileFailsWithGenericMessageAndDoesNotRethrow(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $run = ImportFixtures::run($this->em, $supplier, ImportFixtures::season($this->em), $this->goodRows());
        file_put_contents(\dirname(__DIR__, 3).'/var/imports/'.$run->getStoredPath(), 'pas un tableur');

        ($this->handler)(new AnalyzeImportRun((int) $run->getId()));

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertNotSame('', (string) $run->getError());
        self::assertStringNotContainsString('PhpOffice', (string) $run->getError());
    }

    public function testRunWithoutMappingFails(): void
    {
        $supplier = (new \App\Entity\Supplier())->setName('Sans mapping');
        $this->em->persist($supplier);
        $this->em->flush();
        $run = ImportFixtures::run($this->em, $supplier, ImportFixtures::season($this->em), $this->goodRows());

        ($this->handler)(new AnalyzeImportRun((int) $run->getId()));

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertStringContainsString('mapping', (string) $run->getError());
    }

    public function testNonPendingRunIsLeftUntouched(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $run = ImportFixtures::run($this->em, $supplier, ImportFixtures::season($this->em), $this->goodRows());
        $run->markFailed('déjà en échec');
        $this->em->flush();

        ($this->handler)(new AnalyzeImportRun((int) $run->getId()));

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertSame('déjà en échec', $run->getError());
    }

    public function testUnknownRunIsIgnored(): void
    {
        ($this->handler)(new AnalyzeImportRun(999999));

        $this->addToAssertionCount(1);
    }

    public function testMessageIsRoutedToAsyncTransport(): void
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        \assert($bus instanceof \Symfony\Component\Messenger\MessageBusInterface);
        $bus->dispatch(new AnalyzeImportRun(1));

        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        self::assertCount(1, $transport->getSent());
    }
}
