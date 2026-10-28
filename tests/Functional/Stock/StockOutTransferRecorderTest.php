<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\StockOperation;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Enum\StockOutReason;
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

    private function variant(ProductKind $mode = ProductKind::Consumable, string $ref = 'REF-1', string $ean = '1234567890123'): Variant
    {
        $supplier = $this->em->getRepository(Supplier::class)->findOneBy([]) ?? ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, $ref);
        $product->setKind($mode);
        $provider = static::getContainer()->get(CurrentSeasonProvider::class);
        \assert($provider instanceof CurrentSeasonProvider);
        $season = $provider->get();
        if (null === $season) {
            $season = ImportFixtures::season($this->em);
            $provider->makeCurrent($season);
        }

        return ImportFixtures::variant($this->em, $product, $ean, 'M', true, $season, null);
    }

    private function receive(Variant $variant, Location $location, int $quantity, int $cost): void
    {
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $location, [new StockEntryLine($variant, $quantity, $cost)]));
    }

    private function level(Variant $variant, Location $location, StockCondition $condition = StockCondition::New): ?StockLevel
    {
        $this->em->clear();

        return $this->em->getRepository(StockLevel::class)->findOneBy(['variant' => $variant->getId(), 'location' => $location->getId(), 'condition' => $condition]);
    }

    private function operations(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(o.id) FROM '.StockOperation::class.' o')->getSingleScalarResult();
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
            self::assertSame('Stock insuffisant pour « REF-1 M » (Neuf) à « Local A » : 0 disponible(s).', $e->getMessage());
        }

        self::assertSame($before, $this->operations());
        $level = $this->level($variant, $this->a);
        self::assertSame(0, $level?->getQuantity());
        self::assertSame(0, $level->getValueHt());
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

    public function testLinesOnSameVariantAreSummed(): void
    {
        $variant = $this->variant();
        $this->receive($variant, $this->a, 2, 1000);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('Stock insuffisant pour « REF-1 M » (Neuf) à « Local A » : 2 disponible(s).');
        $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($variant, 2), new StockLine($variant, 2)]));
    }

    public function testOutAndTransferByCondition(): void
    {
        $store = StockFixtures::location($this->em, 'Local matériel');
        $lockers = StockFixtures::location($this->em, 'Vestiaire');
        $variant = $this->variant(ProductKind::Equipment);
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $store, [new StockEntryLine($variant, 2, 3000, StockCondition::Worn)]));
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $store, [new StockEntryLine($variant, 1, 9000)]));

        $operation = $this->recorder->recordTransfer(new StockTransfer($store, $lockers, [new StockLine($variant, 1, StockCondition::Worn)]));

        $before = $this->operations();
        try {
            $this->recorder->recordOut(new StockOut($store, StockOutReason::Broken, [new StockLine($variant, 1, StockCondition::Damaged)]));
            self::fail('StockException attendue.');
        } catch (StockException $e) {
            self::assertSame('Stock insuffisant pour « REF-1 M » (Abîmé) à « Local matériel » : 0 disponible(s).', $e->getMessage());
        }
        self::assertSame($before, $this->operations());

        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(StockCondition::Worn, $movement->getCondition());
        self::assertSame(3000, $movement->getUnitCostHt());
        $moved = $this->level($variant, $lockers, StockCondition::Worn);
        self::assertSame(1, $moved?->getQuantity());
        self::assertSame(3000, $moved->getValueHt());
        $left = $this->level($variant, $store, StockCondition::Worn);
        self::assertSame(1, $left?->getQuantity());
        self::assertSame(3000, $left->getValueHt());
        self::assertSame(1, $this->level($variant, $store)?->getQuantity());
        self::assertNull($this->level($variant, $lockers));
    }

    public function testOutOfEquipmentByCondition(): void
    {
        $variant = $this->variant(ProductKind::Equipment);
        $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->a, [new StockEntryLine($variant, 2, 4000, StockCondition::Good)]));

        $operation = $this->recorder->recordOut(new StockOut($this->a, StockOutReason::Lost, [new StockLine($variant, 1, StockCondition::Good)]));

        $movement = $operation->getMovements()->first();
        self::assertNotFalse($movement);
        self::assertSame(StockCondition::Good, $movement->getCondition());
        self::assertSame(4000, $movement->getUnitCostHt());
        $level = $this->level($variant, $this->a, StockCondition::Good);
        self::assertSame(1, $level?->getQuantity());
        self::assertSame(4000, $level->getValueHt());
    }
}
