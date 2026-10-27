<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stock;

use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\Variant;
use PHPUnit\Framework\TestCase;

final class StockLevelTest extends TestCase
{
    private function level(): StockLevel
    {
        return new StockLevel(new Variant(), new Location());
    }

    public function testWeightedAverage(): void
    {
        $level = $this->level();
        self::assertNull($level->averageCostHt());

        $level->add(3, 3000);
        $level->add(1, 0);
        self::assertSame(4, $level->getQuantity());
        self::assertSame(3000, $level->getValueHt());
        self::assertSame(750, $level->averageCostHt());

        self::assertSame(1500, $level->remove(2));
        self::assertSame(2, $level->getQuantity());
        self::assertSame(1500, $level->getValueHt());

        self::assertSame(1500, $level->remove(2));
        self::assertSame(0, $level->getQuantity());
        self::assertSame(0, $level->getValueHt());
    }

    public function testRemoveRoundsAndEmptiesToZero(): void
    {
        $level = $this->level();
        $level->add(3, 1000);

        self::assertSame(333, $level->remove(1));
        self::assertSame(667, $level->getValueHt());
        self::assertSame(667, $level->remove(2));
        self::assertSame(0, $level->getValueHt());
    }

    public function testRemoveMoreThanAvailableThrows(): void
    {
        $level = $this->level();
        $level->add(1, 100);

        $this->expectException(\LogicException::class);
        $level->remove(2);
    }
}
