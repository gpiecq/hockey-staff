<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\Money\VatCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function cases(): iterable
    {
        yield '360 à 20 %' => [360, 2000, 300];
        yield '50 à 20 %' => [50, 2000, 42];
        yield '1 à 20 %' => [1, 2000, 1];
        yield '0 à 20 %' => [0, 2000, 0];
        yield '1000 à 5,5 %' => [1000, 550, 948];
    }

    #[DataProvider('cases')]
    public function testHtFromTtc(int $ttc, int $rate, int $expected): void
    {
        self::assertSame($expected, VatCalculator::htFromTtc($ttc, $rate));
    }
}
