<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\Ean;
use PHPUnit\Framework\TestCase;

final class EanTest extends TestCase
{
    public function testKey(): void
    {
        self::assertSame('0123456789012', Ean::key('123456789012'));
        self::assertSame('0123456789012', Ean::key(' 0123456789012 '));
        self::assertSame('12345670', Ean::key('12345670'));
    }

    public function testCandidates(): void
    {
        self::assertSame(['0123456789012', '123456789012'], Ean::candidates('123456789012'));
        self::assertSame(['0123456789012', '123456789012'], Ean::candidates('0123456789012'));
        self::assertSame(['4006381333931'], Ean::candidates('4006381333931'));
    }
}
