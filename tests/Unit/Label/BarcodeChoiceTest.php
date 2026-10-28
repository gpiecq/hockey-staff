<?php

declare(strict_types=1);

namespace App\Tests\Unit\Label;

use App\Entity\Variant;
use App\Label\BarcodeChoice;
use App\Label\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BarcodeChoiceTest extends TestCase
{
    public function testTwelveDigitsValidIsUpcA(): void
    {
        $barcode = BarcodeChoice::forVariant((new Variant())->setEan('012345678905'));

        self::assertSame(Symbology::UpcA, $barcode->symbology);
        self::assertSame('012345678905', $barcode->value);
    }

    public function testThirteenDigitsValidIsEan13(): void
    {
        $barcode = BarcodeChoice::forVariant((new Variant())->setEan('4006381333931'));

        self::assertSame(Symbology::Ean13, $barcode->symbology);
        self::assertSame('4006381333931', $barcode->value);
    }

    #[DataProvider('unusableEans')]
    public function testInvalidOrMissingEanFallsBackToVariantCode(?string $ean): void
    {
        $variant = (new Variant())->setEan($ean);
        $variant->assignCode('HV-000001');

        $barcode = BarcodeChoice::forVariant($variant);

        self::assertSame(Symbology::Code128, $barcode->symbology);
        self::assertSame('HV-000001', $barcode->value);
    }

    /** @return iterable<string, array{?string}> */
    public static function unusableEans(): iterable
    {
        yield 'bad check digit' => ['876369003339'];
        yield 'null' => [null];
        yield 'not numeric' => ['ABC123'];
    }
}
