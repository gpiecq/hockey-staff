<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sale;

use App\Sale\SalePricing;
use PHPUnit\Framework\TestCase;

final class SalePricingTest extends TestCase
{
    public function testDiscountedMatchesUserExample(): void
    {
        self::assertSame(6000, SalePricing::discounted(30000, 8000));
        self::assertSame(6666, SalePricing::discounted(9999, 3333));
        self::assertSame(30000, SalePricing::discounted(30000, 0));

        $this->expectException(\InvalidArgumentException::class);
        SalePricing::discounted(30000, 10001);
    }
}
