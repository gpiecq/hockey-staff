<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stock;

use App\Entity\StockLevel;
use App\Repository\ItemRepository;
use App\Tests\Support\ImportFixtures;
use App\Tests\Support\StockFixtures;
use Doctrine\DBAL\Exception\DriverException;
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
