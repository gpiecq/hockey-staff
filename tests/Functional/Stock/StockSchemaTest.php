<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\PlayerHolding;
use App\Entity\StockLevel;
use App\Enum\StockCondition;
use App\Repository\ItemRepository;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\PlayerFixtures;
use App\Tests\Support\StockFixtures;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StockSchemaTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    public function testNegativeQuantityRejectedByDatabase(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $product = ImportFixtures::product($this->em, $supplier, 'REF-1');
        $variant = ImportFixtures::variant($this->em, $product, '1234567890123');
        $location = StockFixtures::location($this->em);

        $level = new StockLevel($variant, $location);
        $this->em->persist($level);
        $this->em->flush();

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('chk_stock_level_quantity');
        $this->em->getConnection()->executeStatement('UPDATE stock_level SET quantity = -1');
    }

    public function testStockLevelIsUniquePerVariantLocationAndCondition(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $variant = ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'REF-1'), '1234567890123');
        $location = StockFixtures::location($this->em);
        StockFixtures::level($this->em, $variant, $location, 1, 100);
        StockFixtures::level($this->em, $variant, $location, 1, 100, StockCondition::Worn);

        self::assertSame("'new'::character varying", $this->columnDefault('stock_level', 'condition'));
        self::assertSame("'new'::character varying", $this->columnDefault('stock_movement', 'condition'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_stock_level_variant_location_condition');
        StockFixtures::level($this->em, $variant, $location, 1, 100, StockCondition::Worn);
    }

    public function testPlayerHoldingIsUniquePerPlayerAndVariant(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $variant = ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'REF-1'), '1234567890123');
        $player = PlayerFixtures::player($this->em);
        $this->em->persist(new PlayerHolding($player, $variant));
        $this->em->flush();

        self::assertSame('r', $this->em->getConnection()->fetchOne(
            "SELECT confdeltype FROM pg_constraint WHERE conrelid = 'player_holding'::regclass AND contype = 'f' AND confrelid = 'player'::regclass",
        ));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_player_holding');
        $this->em->persist(new PlayerHolding($player, $variant));
        $this->em->flush();
    }

    private function columnDefault(string $table, string $column): mixed
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT column_default FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$table, $column],
        );
    }

    public function testItemCodesAreSequential(): void
    {
        $repository = static::getContainer()->get(ItemRepository::class);
        \assert($repository instanceof ItemRepository);

        $first = $repository->nextCode();
        $second = $repository->nextCode();

        self::assertMatchesRegularExpression('/^HS-\d{6}$/', $first);
        self::assertSame((int) substr($first, 3) + 1, (int) substr($second, 3));
    }
}
