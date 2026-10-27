<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Item;
use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\StockMovementType;
use App\Enum\StockOutReason;
use App\Enum\TrackingMode;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockOut;
use App\Stock\Input\StockTransfer;
use App\Stock\StockException;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StockOutTransferRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockMovementRecorder $recorder;
    private Location $a;
    private Location $b;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $recorder = static::getContainer()->get(StockMovementRecorder::class);
        \assert($recorder instanceof StockMovementRecorder);
        $this->recorder = $recorder;
        $this->a = StockFixtures::location($this->em, 'Local A');
        $this->b = StockFixtures::location($this->em, 'Local B');
    }

    private function variant(TrackingMode $mode = TrackingMode::Quantity): Variant
    {
        $supplier = $this->em->getRepository(Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, 'REF-1');
        $product->setTrackingMode($mode);
        $provider = static::getContainer()->get(CurrentSeasonProvider::class);
        \assert($provider instanceof CurrentSeasonProvider);
        $season = $provider->get();
        if (null === $season) {
            $season = ImportFixtures::season($this->em);
            $provider->makeCurrent($season);
        }

        return ImportFixtures::variant($this->em, $product, '1234567890123', 'M', true, $season, null);
    }

    private function receive(Variant $variant, Location $location, int $quantity, int $cost): void
    {
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [new StockEntryLine($variant, $quantity, $cost)]));
    }

    private function level(Variant $variant, Location $location): ?StockLevel
    {
        $this->em->clear();

        return $this->em->getRepository(StockLevel::class)->findOneBy(['variant' => $variant->getId(), 'location' => $location->getId()]);
    }

    private function operations(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.StockOperation::class.' o')->getSingleScalarResult();
    }

    private function serialized(int $cost = 8000, ?Location $location = null): Item
    {
        $variant = $this->variant(TrackingMode::Serialized);
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location ?? $this->a, [new StockEntryLine($variant, 1, $cost)]));
        $item = $this->em->getRepository(Item::class)->findOneBy([]);
        self::assertNotNull($item);

        return $item;
    }

    public function testOutOfQuantityUsesAverageCost(): void
    {
        $variant = $this->variant();
        $this->receive($variant, $this->a, 2, 1000);
        $this->receive($variant, $this->a, 2, 2000);

        $operation = $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Broken, [new StockLine($variant, 1)]));

        self::assertSame(StockMovementType::Out, $operation->getType());
        self::assertSame(StockOutReason::Broken, $operation->getOutReason());
        self::assertNull($operation->getDestination());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(1500, $movement->getUnitCostHt());
        $level = $this->level($variant, $this->a);
        self::assertSame(3, $level?->getQuantity());
        self::assertSame(4500, $level->getValueHt());
    }

    public function testOutOfSerializedItem(): void
    {
        $item = $this->serialized();

        $operation = $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($item->getVariant(), 1, $item)]));

        self::assertSame(ItemStatus::Removed, $item->getStatus());
        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(8000, $movement->getUnitCostHt());
        self::assertSame($item->getId(), $movement->getItem()?->getId());
    }

    public function testSecondOutOfLastUnitIsRefused(): void
    {
        $variant = $this->variant();
        $this->receive($variant, $this->a, 1, 1000);
        $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($variant, 1)]));
        $before = $this->operations();

        try {
            $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($variant, 1)]));
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertSame('Stock insuffisant pour « REF-1 M » à « Local A » : 0 disponible(s).', $e->getMessage());
        }

        self::assertSame($before, $this->operations());
        $level = $this->level($variant, $this->a);
        self::assertSame(0, $level?->getQuantity());
        self::assertSame(0, $level->getValueHt());
    }

    public function testItemFromOtherLocationIsRefused(): void
    {
        $item = $this->serialized();
        $code = $item->getCode();
        $before = $this->operations();

        try {
            $this->recorder->recordOut(new StockOut($this->b, StockOutReason::Lost, [new StockLine($item->getVariant(), 1, $item)]));
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertSame(\sprintf('La pièce %s n\'est pas en stock à « Local B ».', $code), $e->getMessage());
        }

        self::assertSame(ItemStatus::InStock, $item->getStatus());
        self::assertSame($before, $this->operations());
    }

    public function testStaleItemIsRereadBeforeCheck(): void
    {
        $item = $this->serialized();
        $this->em->getConnection()->executeStatement("UPDATE item SET status = 'removed' WHERE id = ?", [$item->getId()]);

        $this->expectException(StockException::class);
        $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($item->getVariant(), 1, $item)]));
    }

    public function testTransferMovesQuantityAndValue(): void
    {
        $variant = $this->variant();
        $this->receive($variant, $this->a, 4, 1500);

        $operation = $this->recorder->recordTransfer(new StockTransfer($this->a, $this->b, [new StockLine($variant, 1)]));

        self::assertSame(StockMovementType::Transfer, $operation->getType());
        self::assertSame($this->a->getId(), $operation->getSource()?->getId());
        self::assertSame($this->b->getId(), $operation->getDestination()?->getId());
        $levelA = $this->level($variant, $this->a);
        self::assertSame(3, $levelA?->getQuantity());
        self::assertSame(4500, $levelA->getValueHt());
        $levelB = $this->level($variant, $this->b);
        self::assertSame(1, $levelB?->getQuantity());
        self::assertSame(1500, $levelB->getValueHt());
    }

    public function testTransferMovesItemKeepingCost(): void
    {
        $item = $this->serialized();

        $this->recorder->recordTransfer(new StockTransfer($this->a, $this->b, [new StockLine($item->getVariant(), 1, $item)]));

        self::assertSame($this->b->getId(), $item->getLocation()->getId());
        self::assertSame(8000, $item->getPurchaseCostHt());
    }

    public function testSameLocationTransferIsRefused(): void
    {
        $variant = $this->variant();

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Les lieux de départ et d\'arrivée doivent être différents.');
        $this->recorder->recordTransfer(new StockTransfer($this->a, $this->a, [new StockLine($variant, 1)]));
    }

    public function testInactiveDestinationIsRefused(): void
    {
        $variant = $this->variant();
        $cave = StockFixtures::location($this->em, 'Cave', false);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Le lieu « Cave » est inactif.');
        $this->recorder->recordTransfer(new StockTransfer($this->a, $cave, [new StockLine($variant, 1)]));
    }

    public function testOtherReasonRequiresComment(): void
    {
        $variant = $this->variant();

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Précisez le motif en commentaire.');
        $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Other, [new StockLine($variant, 1)]));
    }

    public function testSerializedLineWithoutItemIsRefused(): void
    {
        $variant = $this->variant(TrackingMode::Serialized);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Indiquez la pièce (code HS-) pour « REF-1 M ».');
        $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($variant, 1)]));
    }

    public function testSameItemTwiceIsRefused(): void
    {
        $item = $this->serialized();

        $this->expectException(StockException::class);
        $this->expectExceptionMessage(\sprintf('La pièce %s figure deux fois.', $item->getCode()));
        $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($item->getVariant(), 1, $item), new StockLine($item->getVariant(), 1, $item)]));
    }

    public function testLinesOnSameVariantAreSummed(): void
    {
        $variant = $this->variant();
        $this->receive($variant, $this->a, 2, 1000);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Stock insuffisant pour « REF-1 M » à « Local A » : 2 disponible(s).');
        $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($variant, 2), new StockLine($variant, 2)]));
    }
}
