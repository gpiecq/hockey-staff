<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Entity\Supplier;
use App\Entity\SupplierImportMapping;
use App\Enum\ImportAnomalyCode;
use App\Import\NormalizedRow;
use App\Import\RowNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RowNormalizerTest extends TestCase
{
    /** @param array<string, ?string> $overrides */
    private function normalize(array $overrides = []): NormalizedRow
    {
        $mapping = (new SupplierImportMapping(new Supplier()))
            ->setColorAliases(['BLK' => 'NOIR', 'WHT' => 'BLANC', 'NAVY' => 'MARINE', 'NAV' => 'MARINE', 'RED' => 'ROUGE']);
        $raw = $overrides + [
            'reference' => 'R1', 'ean' => '688698508062', 'designation' => 'Patin', 'size' => null, 'fit' => null,
            'side' => null, 'width' => null, 'color' => null, 'publicPriceTtc' => '10', 'clubPriceTtc' => '8',
            'category1' => 'EQUIPEMENT', 'category2' => null, 'category3' => null, 'brand' => 'BAUER',
        ];

        return (new RowNormalizer())->normalize(7, $raw, $mapping);
    }

    /** @return list<string> */
    private function codes(NormalizedRow $row): array
    {
        return array_map(static fn ($a) => $a->code->name, $row->anomalies);
    }

    public function testCleanRowHasNoAnomaly(): void
    {
        $row = $this->normalize();

        self::assertSame([], $row->anomalies);
        self::assertFalse($row->isRejected());
        self::assertSame('688698508062', $row->ean);
        self::assertSame(800, $row->clubPriceTtc);
        self::assertSame(1000, $row->publicPriceTtc);
        self::assertSame(7, $row->line);
    }

    public function testElevenDigitEanIsPadded(): void
    {
        $row = $this->normalize(['ean' => '67235585931']);

        self::assertSame('067235585931', $row->ean);
        self::assertSame(['EanPadded'], $this->codes($row));
        self::assertSame(7, $row->anomalies[0]->line);
    }

    #[DataProvider('eans')]
    public function testEan(?string $input, ?string $expected, ?string $code): void
    {
        $row = $this->normalize(['ean' => $input]);

        self::assertSame($expected, $row->ean);
        self::assertSame(null === $code ? [] : [$code], $this->codes($row));
        self::assertFalse($row->isRejected());
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function eans(): iterable
    {
        yield 'too short' => ['12345', null, 'EanInvalid'];
        yield 'empty' => ['', null, 'EanMissing'];
        yield 'null' => [null, null, 'EanMissing'];
        yield 'blank' => ['  ', null, 'EanMissing'];
        yield 'letters' => ['12345678901A', null, 'EanInvalid'];
        yield 'ean8' => ['12345678', '12345678', null];
        yield 'ean13 with spaces' => [' 3 760 22 6 6 28 56 7 ', '3760226628567', null];
        yield 'ean14' => ['12345678901234', '12345678901234', null];
        yield '15 digits' => ['123456789012345', null, 'EanInvalid'];
    }

    #[DataProvider('prices')]
    public function testPrice(?string $input, ?int $cents, ?string $code): void
    {
        $row = $this->normalize(['clubPriceTtc' => $input]);

        self::assertSame($cents, $row->clubPriceTtc);
        self::assertSame(null === $code ? [] : [$code], $this->codes($row));
        self::assertSame(1000, $row->publicPriceTtc);
    }

    /** @return iterable<string, array{?string, ?int, ?string}> */
    public static function prices(): iterable
    {
        yield 'dot' => ['0.5', 50, null];
        yield 'comma' => ['12,5', 1250, null];
        yield 'spaces' => [' 3 ', 300, null];
        yield 'nbsp' => ["1\u{00A0}234,56", 123456, null];
        yield 'integer' => ['12', 1200, null];
        yield 'two decimals' => ['12.05', 1205, null];
        yield 'empty' => ['', null, 'PriceMissing'];
        yield 'null' => [null, null, 'PriceMissing'];
        yield 'text' => ['N/A', null, 'PriceInvalid'];
        yield 'three decimals' => ['1.234', null, 'PriceInvalid'];
        yield 'negative' => ['-3', null, 'PriceInvalid'];
        yield 'two separators' => ['1,234.5', null, 'PriceInvalid'];
    }

    public function testPublicPriceMissingIsReportedOnItsOwn(): void
    {
        $row = $this->normalize(['publicPriceTtc' => null]);

        self::assertNull($row->publicPriceTtc);
        self::assertSame(800, $row->clubPriceTtc);
        self::assertSame(['PriceMissing'], $this->codes($row));
        self::assertStringContainsString('public', $row->anomalies[0]->message);
    }

    public function testColorInSizeIsMovedWhenColorEmpty(): void
    {
        $row = $this->normalize(['size' => 'NOIR']);

        self::assertNull($row->size);
        self::assertSame('NOIR', $row->color);
        self::assertSame(['ColorMovedFromSize'], $this->codes($row));
    }

    public function testColorInSizeIsCaseInsensitive(): void
    {
        $row = $this->normalize(['size' => ' blanc ']);

        self::assertNull($row->size);
        self::assertSame('BLANC', $row->color);
    }

    public function testColorInSizeIsKeptWhenColorFilled(): void
    {
        $row = $this->normalize(['size' => 'BLANC', 'color' => 'NOIR']);

        self::assertSame('BLANC', $row->size);
        self::assertSame('NOIR', $row->color);
        self::assertSame([], $this->codes($row));
    }

    public function testAliasKeyInSizeIsMovedThenAliased(): void
    {
        $row = $this->normalize(['size' => 'Navy']);

        self::assertNull($row->size);
        self::assertSame('MARINE', $row->color);
        self::assertSame(['ColorMovedFromSize', 'ColorAliased'], $this->codes($row));
    }

    #[DataProvider('colorWords')]
    public function testEveryColorWordIsRecognizedInSize(string $word): void
    {
        $row = $this->normalize(['size' => $word]);

        self::assertNull($row->size);
        self::assertSame($word, $row->color);
    }

    /** @return iterable<string, array{string}> */
    public static function colorWords(): iterable
    {
        foreach (['NOIR', 'BLANC', 'ROUGE', 'BLEU', 'MARINE', 'GRIS', 'ROSE', 'VERT', 'ORANGE', 'JAUNE', 'VIOLET'] as $w) {
            yield $w => [$w];
        }
    }

    public function testNonColorSizeIsUntouched(): void
    {
        $row = $this->normalize(['size' => 'SR L']);

        self::assertSame('SR L', $row->size);
        self::assertNull($row->color);
    }

    public function testColorAlias(): void
    {
        $row = $this->normalize(['color' => 'blk']);

        self::assertSame('NOIR', $row->color);
        self::assertSame(['ColorAliased'], $this->codes($row));
    }

    public function testUnknownColorIsUnchanged(): void
    {
        $row = $this->normalize(['color' => 'BKR']);

        self::assertSame('BKR', $row->color);
        self::assertSame([], $this->codes($row));
    }

    public function testAttributesAreTrimmedAndEmptyBecomeNull(): void
    {
        $row = $this->normalize(['fit' => ' Fit2 ', 'side' => '  ', 'width' => '']);

        self::assertSame('Fit2', $row->fit);
        self::assertNull($row->side);
        self::assertNull($row->width);
    }

    public function testCategoryPath(): void
    {
        $row = $this->normalize(['category1' => 'equipement', 'category2' => 'GARDIEN', 'category3' => 'PROTEGE  GENOUX ']);
        self::assertSame(['EQUIPEMENT', 'GARDIEN', 'PROTEGE GENOUX'], $row->categoryPath);

        $row = $this->normalize(['category1' => 'CONSOMMABLE']);
        self::assertSame(['CONSOMMABLE'], $row->categoryPath);
    }

    public function testBrandIsNormalizedLikeBrandEntity(): void
    {
        self::assertSame('BLUE SPORT', $this->normalize(['brand' => ' blue   sport '])->brand);
    }

    public function testMissingRequiredFieldsAreReportedOnePerField(): void
    {
        $row = $this->normalize(['reference' => '  ', 'designation' => null, 'category1' => '', 'brand' => null]);

        self::assertSame(['MissingRequired', 'MissingRequired', 'MissingRequired', 'MissingRequired'], $this->codes($row));
        self::assertTrue($row->isRejected());
        self::assertSame('', $row->reference);
        self::assertSame([], $row->categoryPath);
        self::assertStringContainsString('Référence', $row->anomalies[0]->message);
    }

    public function testMissingReferenceOnly(): void
    {
        $row = $this->normalize(['reference' => '']);

        self::assertSame([ImportAnomalyCode::MissingRequired], array_map(static fn ($a) => $a->code, $row->anomalies));
        self::assertTrue($row->isRejected());
    }

    public function testMatchKeyMatchesVariantFormula(): void
    {
        self::assertSame('SR L|FIT2|LFT||NOIR', $this->normalize(['size' => 'SR L', 'fit' => 'FIT2', 'side' => 'LFT', 'color' => 'NOIR'])->matchKey());
        self::assertSame('||||', $this->normalize()->matchKey());
    }
}
