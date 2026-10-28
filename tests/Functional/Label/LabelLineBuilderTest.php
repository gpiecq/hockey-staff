<?php

declare(strict_types=1);

namespace App\Tests\Functional\Label;

use App\Entity\Location;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockMovementType;
use App\Enum\StockOutReason;
use App\Label\LabelLine;
use App\Label\LabelLineBuilder;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockOut;
use App\Stock\StockMovementRecorder;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use App\Tests\Support\StockScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LabelLineBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockMovementRecorder $recorder;
    private LabelLineBuilder $builder;
    private Location $location;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $recorder = $container->get(StockMovementRecorder::class);
        $builder = $container->get(LabelLineBuilder::class);
        \assert($em instanceof EntityManagerInterface && $recorder instanceof StockMovementRecorder && $builder instanceof LabelLineBuilder);
        $this->em = $em;
        $this->recorder = $recorder;
        $this->builder = $builder;
        $this->location = StockFixtures::location($this->em, 'Local A');
    }

    /**
     * @param list<LabelLine> $lines
     *
     * @return list<string>
     */
    private function codes(array $lines): array
    {
        return array_map(static fn (LabelLine $line): string => $line->code(), $lines);
    }

    public function testStockedEquipmentGivesOneVariantLine(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-S', 'Casque', '4006381333931');
        StockScenario::receive($this->recorder, $variant, $this->location, 3, 5000);
        $product = $variant->getProduct();
        self::assertNotNull($product);

        foreach ([$this->builder->forVariants([$variant]), $this->builder->forProducts([$product])] as $lines) {
            self::assertCount(1, $lines);
            self::assertSame($variant, $lines[0]->variant);
            self::assertSame(1, $lines[0]->copies);
            self::assertSame('4006381333931', $lines[0]->code());
        }
    }

    public function testConsumableGivesVariantLine(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        StockScenario::receive($this->recorder, $variant, $this->location, 4, 300);

        $lines = $this->builder->forVariants([$variant]);

        self::assertCount(1, $lines);
        self::assertSame($variant, $lines[0]->variant);
    }

    public function testArchivedVariantWithStockIsLabelable(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-A', 'Casque');
        StockScenario::receive($this->recorder, $variant, $this->location, 1, 5000);
        $variant->archive();
        $this->em->flush();
        $product = $variant->getProduct();
        self::assertNotNull($product);

        $lines = $this->builder->forProducts([$product]);

        self::assertCount(1, $lines);
        self::assertSame($variant, $lines[0]->variant);
        $withoutEan = $this->builder->withoutValidEan();
        self::assertCount(1, $withoutEan);
        self::assertSame($variant, $withoutEan[0]->variant);
    }

    public function testInactiveVariantWithoutStockIsExcluded(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Consumable, 'REF-I', 'Ruban');
        $variant->setActive(false);
        $this->em->flush();
        $product = $variant->getProduct();
        self::assertNotNull($product);

        self::assertSame([], $this->builder->forProducts([$product]));
        self::assertSame([], $this->builder->forVariants([$variant]));
        self::assertSame([], $this->builder->withoutValidEan());
    }

    public function testWithoutValidEanIncludesInvalidCheckDigit(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, 'REF-E', 'Gant');
        $none = ImportFixtures::variant($this->em, $product, null, 'S');
        $bad = ImportFixtures::variant($this->em, $product, '876369003339', 'M');
        ImportFixtures::variant($this->em, $product, '012345678905', 'L');

        $lines = $this->builder->withoutValidEan();

        self::assertSame([$none, $bad], array_map(static fn (LabelLine $line): Variant => $line->variant, $lines));
        self::assertSame([$none->getCode(), $bad->getCode()], $this->codes($lines));
    }

    public function testEntryOperationLines(): void
    {
        $serialized = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-S', 'Casque');
        $quantity = StockScenario::variant($this->em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        $operation = $this->recorder->recordEntry(new StockEntry(StockMovementType::PurchaseIn, $this->location, [
            new StockEntryLine($serialized, 2, 5000),
            new StockEntryLine($quantity, 5, 300),
        ]));

        $lines = $this->builder->forOperation($operation);

        self::assertCount(2, $lines);
        self::assertSame($serialized, $lines[0]->variant);
        self::assertSame(2, $lines[0]->copies);
        self::assertSame($quantity, $lines[1]->variant);
        self::assertSame(5, $lines[1]->copies);

        $out = $this->recorder->recordOut(new StockOut($this->location, StockOutReason::Lost, [new StockLine($quantity, 1)]));
        self::assertSame([], $this->builder->forOperation($out));
    }
}
