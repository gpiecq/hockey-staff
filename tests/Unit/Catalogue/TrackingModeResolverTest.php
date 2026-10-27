<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Catalogue\TrackingModeResolver;
use App\Entity\Category;
use App\Enum\TrackingMode;
use PHPUnit\Framework\TestCase;

final class TrackingModeResolverTest extends TestCase
{
    public function testEquipmentLeafIsSerialized(): void
    {
        $equipement = (new Category())->setName('Equipement');
        $joueur = (new Category())->setName('Joueur')->setParent($equipement);
        $patin = (new Category())->setName('Patin')->setParent($joueur);

        self::assertSame(TrackingMode::Serialized, (new TrackingModeResolver())->defaultFor($patin));
    }

    public function testConsumableIsQuantity(): void
    {
        $consommable = (new Category())->setName('Consommable');

        self::assertSame(TrackingMode::Quantity, (new TrackingModeResolver())->defaultFor($consommable));
    }
}
