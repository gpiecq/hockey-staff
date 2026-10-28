<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\ProductKindResolver;
use App\Entity\Category;
use App\Enum\ProductKind;
use PHPUnit\Framework\TestCase;

final class ProductKindResolverTest extends TestCase
{
    public function testEquipmentLeafIsEquipment(): void
    {
        $equipement = (new Category())->setName('Equipement');
        $joueur = (new Category())->setName('Joueur')->setParent($equipement);
        $patin = (new Category())->setName('Patin')->setParent($joueur);

        self::assertSame(ProductKind::Equipment, (new ProductKindResolver())->defaultFor($patin));
    }

    public function testOtherRootIsConsumable(): void
    {
        $consommable = (new Category())->setName('Consommable');

        self::assertSame(ProductKind::Consumable, (new ProductKindResolver())->defaultFor($consommable));
    }
}
