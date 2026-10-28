<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Item;
use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\ProductKind;
use App\Enum\StockMovementType;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StockEntryRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockMovementRecorder $recorder;
    private Location $location;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $recorder = static::getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $this->recorder = $recorder;
        $this->location = StockFixtures::location($this->em);
    }

    private function variant(ProductKind $mode = ProductKind::Consumable, ?int $club = null, ?string $ean = '1234567890123', string $ref = 'REF-1'): Variant
    {
        $supplier = $this->em->getRepository(\App\Entity\Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, $ref);
        $product->setKind($mode);
        $season = $this->currentSeason();
        $variant = ImportFixtures::variant($this->em, $product, $ean, 'M', true, $season, $club);

        return $variant;
    }

    private function currentSeason(): \App\Entity\Season
    {
        $provider = static::getContainer()->get(CurrentSeasonProvider::class);
        \assert($provider instanceof CurrentSeasonProvider);
        $season = $provider->get();
        if (null === $season) {
            $season = ImportFixtures::season($this->em);
            $provider->makeCurrent($season);
        }

        return $season;
    }

    private function rows(string $class): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(e.id) FROM '.$class.' e')->getSingleScalarResult();
    }

    public function testPurchaseOfQuantityProductCreatesLevel(): void
    {
        $variant = $this->variant();

        $operation = $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [new StockEntryLine($variant, 3, 1250)], 'BL-1', 'note'));

        self::assertSame(StockMovementType::PurchaseIn, $operation->getType());
        self::assertSame('BL-1', $operation->getDocumentReference());
        self::assertCount(1, $operation->getMovements());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(3, $movement->getQuantity());
        self::assertSame(1250, $movement->getUnitCostHt());
        self::assertSame($this->currentSeason()->getId(), $movement->getSeason()?->getId());
        $level = $this->em->getRepository(StockLevel::class)->findOneBy(['variant' => $variant, 'location' => $this->location]);
        self::assertSame(3, $level?->getQuantity());
        self::assertSame(3750, $level->getValueHt());
    }

    public function testPurchaseCostDefaultsToClubPriceHt(): void
    {
        $variant = $this->variant(club: 12000);

        $operation = $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [new StockEntryLine($variant, 1)]));

        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(10000, $movement->getUnitCostHt());
    }

    public function testPurchaseOfSerializedProductCreatesItems(): void
    {
        $variant = $this->variant(ProductKind::Equipment);

        $operation = $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [new StockEntryLine($variant, 2, 900, serialNumbers: [' SN1 ', ''])]));

        $items = $this->em->getRepository(Item::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $items);
        self::assertNotSame($items[0]->getCode(), $items[1]->getCode());
        foreach ($items as $item) {
            self::assertMatchesRegularExpression('/^HS-\d{6}$/', $item->getCode());
            self::assertSame(ItemStatus::InStock, $item->getStatus());
            self::assertSame($this->location->getId(), $item->getLocation()->getId());
            self::assertSame(900, $item->getPurchaseCostHt());
        }
        self::assertSame('SN1', $items[0]->getSerialNumber());
        self::assertNull($items[1]->getSerialNumber());
        self::assertCount(2, $operation->getMovements());
        foreach ($operation->getMovements() as $i => $movement) {
            self::assertSame(1, $movement->getQuantity());
            self::assertSame($items[$i]->getId(), $movement->getItem()?->getId());
        }
        self::assertSame(0, $this->rows(StockLevel::class));
    }

    public function testDonationIsFreeWithCatalogueValue(): void
    {
        $variant = $this->variant(club: 12000);

        $operation = $this->recorder->recordEntry(new StockEntry(StockMovementType::DonationIn, $this->location, [new StockEntryLine($variant, 2, 555)]));

        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(0, $movement->getUnitCostHt());
        self::assertSame(10000, $movement->getCatalogueValueHt());
        $level = $this->em->getRepository(StockLevel::class)->findOneBy(['variant' => $variant]);
        self::assertSame(0, $level?->getValueHt());
        self::assertSame(2, $level->getQuantity());
    }

    public function testDonationWithoutTariffIsAccepted(): void
    {
        $variant = $this->variant();

        $operation = $this->recorder->recordEntry(new StockEntry(StockMovementType::DonationIn, $this->location, [new StockEntryLine($variant, 1)]));

        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertNull($movement->getCatalogueValueHt());
    }

    public function testInvalidLineRollsBackWholeEntry(): void
    {
        $ok = $this->variant(ean: '1234567890123', ref: 'REF-1');
        $ko = $this->variant(ean: '1234567890124', ref: 'REF-2');

        try {
            $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [
                new StockEntryLine($ok, 1, 100),
                new StockEntryLine($ko, 1),
            ]));
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertStringStartsWith('Coût unitaire manquant pour « REF-2 M » : aucun tarif club pour la saison courante.', $e->getMessage());
        }

        $this->em->clear();
        self::assertSame(0, $this->rows(StockOperation::class));
        self::assertSame(0, $this->rows(StockLevel::class));
        self::assertSame(0, $this->rows(Item::class));
    }

    public function testNegativeCostIsRefused(): void
    {
        $variant = $this->variant();

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Le coût unitaire ne peut pas être négatif.');
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [new StockEntryLine($variant, 1, -1)]));
    }

    public function testQuantityBelowOneIsRefused(): void
    {
        $variant = $this->variant();

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('La quantité doit être au moins 1.');
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [new StockEntryLine($variant, 0, 100)]));
    }

    public function testTooManySerialNumbersIsRefused(): void
    {
        $variant = $this->variant(ProductKind::Equipment);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Plus de numéros de série que de pièces pour « REF-1 M ».');
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [new StockEntryLine($variant, 1, 100, serialNumbers: ['A', 'B'])]));
    }

    public function testArchivedVariantCannotBeReceived(): void
    {
        $variant = $this->variant();
        $variant->archive();
        $this->em->flush();

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('« REF-1 M » n\'est plus au catalogue : entrée impossible.');
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [new StockEntryLine($variant, 1, 100)]));
    }

    public function testInactiveLocationIsRefused(): void
    {
        $variant = $this->variant();
        $inactive = StockFixtures::location($this->em, 'Cave', false);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Le lieu « Cave » est inactif.');
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $inactive, [new StockEntryLine($variant, 1, 100)]));
    }

    public function testEmptyEntryIsRefused(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Ajoutez au moins une ligne.');
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, []));
    }
}
