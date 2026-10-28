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
use App\Label\LabelMode;
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

    public function testSerializedVariantInItemsModeGivesOneLinePerPiece(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-S', 'Casque', '4006381333931');
        StockScenario::receive($this->recorder, $variant, $this->location, 3, 5000);
        $product = $variant->getProduct();
        self::assertNotNull($product);

        $lines = $this->builder->forProducts([$product], LabelMode::Items);

        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertNull($line->variant);
            self::assertNotNull($line->item);
            self::assertSame(1, $line->copies);
        }
        $codes = $this->codes($lines);
        self::assertMatchesRegularExpression('/^HS-\d{6}$/', $codes[0]);
        $sorted = $codes;
        sort($sorted);
        self::assertSame($sorted, $codes);
        self::assertTrue($this->builder->hasItemChoice([$variant]));
    }

    public function testVariantsModeGivesVariantLine(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-S', 'Casque', '4006381333931');
        StockScenario::receive($this->recorder, $variant, $this->location, 3, 5000);

        $lines = $this->builder->forVariants([$variant], LabelMode::Variants);

        self::assertCount(1, $lines);
        self::assertSame($variant, $lines[0]->variant);
        self::assertNull($lines[0]->item);
        self::assertSame(1, $lines[0]->copies);
        self::assertSame('4006381333931', $lines[0]->code());
    }

    public function testSerializedVariantWithoutPieceGivesVariantLine(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-S', 'Casque');

        $lines = $this->builder->forVariants([$variant], LabelMode::Items);

        self::assertCount(1, $lines);
        self::assertSame($variant, $lines[0]->variant);
        self::assertFalse($this->builder->hasItemChoice([$variant]));
    }

    public function testQuantityProductAlwaysGivesVariantLine(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Consumable, 'REF-Q', 'Ruban');
        StockScenario::receive($this->recorder, $variant, $this->location, 4, 300);

        $lines = $this->builder->forVariants([$variant], LabelMode::Items);

        self::assertCount(1, $lines);
        self::assertSame($variant, $lines[0]->variant);
        self::assertFalse($this->builder->hasItemChoice([$variant]));
    }

    public function testArchivedVariantWithStockIsLabelable(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-A', 'Casque');
        StockScenario::receive($this->recorder, $variant, $this->location, 1, 5000);
        $variant->archive();
        $this->em->flush();
        $product = $variant->getProduct();
        self::assertNotNull($product);

        $lines = $this->builder->forProducts([$product], LabelMode::Items);

        self::assertCount(1, $lines);
        self::assertNotNull($lines[0]->item);
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

        self::assertSame([], $this->builder->forProducts([$product], LabelMode::Variants));
        self::assertSame([], $this->builder->forVariants([$variant], LabelMode::Variants));
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

        self::assertSame([$none, $bad], array_map(static fn (LabelLine $line): ?Variant => $line->variant, $lines));
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

        self::assertCount(3, $lines);
        self::assertNotNull($lines[0]->item);
        self::assertNotNull($lines[1]->item);
        self::assertSame($quantity, $lines[2]->variant);
        self::assertSame(5, $lines[2]->copies);

        $out = $this->recorder->recordOut(new StockOut($this->location, StockOutReason::Lost, [new StockLine($quantity, 1)]));
        self::assertSame([], $this->builder->forOperation($out));
    }

    public function testForItemGivesOneLine(): void
    {
        $variant = StockScenario::variant($this->em, ProductKind::Equipment, 'REF-S', 'Casque');
        StockScenario::receive($this->recorder, $variant, $this->location, 1, 5000);
        $item = $this->em->getRepository(\App\Entity\Item::class)->findOneBy([]);
        self::assertNotNull($item);

        $lines = $this->builder->forItem($item);

        self::assertCount(1, $lines);
        self::assertSame($item, $lines[0]->item);
        self::assertSame($item->getCode(), $lines[0]->code());
    }
}
