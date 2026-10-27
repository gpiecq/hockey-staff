<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Entity\Variant;
use PHPUnit\Framework\TestCase;

final class VariantTest extends TestCase
{
    public function testMatchKey(): void
    {
        $variant = (new Variant())->setSize('SR L')->setFit('FIT2')->setColor('NOIR');

        self::assertSame('SR L|FIT2|||NOIR', $variant->getMatchKey());
    }

    public function testMatchKeyFollowsChanges(): void
    {
        $variant = (new Variant())->setSize('SR L');
        $variant->setSize('JR M')->setSide('LEFT');

        self::assertSame('JR M||LEFT||', $variant->getMatchKey());
    }

    public function testLabelSkipsEmptyAttributes(): void
    {
        $variant = (new Variant())->setSize('SR L')->setFit('')->setColor('NOIR');

        self::assertSame('SR L · NOIR', $variant->getLabel());
    }

    public function testDefaults(): void
    {
        $variant = new Variant();

        self::assertTrue($variant->isActive());
        self::assertSame([], $variant->getExtraAttributes());
        self::assertSame('', $variant->getLabel());
    }
}
