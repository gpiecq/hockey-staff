<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\Variant;
use App\Enum\StockCondition;
use Doctrine\ORM\EntityManagerInterface;

final class StockFixtures
{
    public static function location(EntityManagerInterface $em, string $name = 'Local matériel', bool $active = true): Location
    {
        $location = (new Location())->setName($name)->setActive($active);
        $em->persist($location);
        $em->flush();

        return $location;
    }

    /**
     * Niveau de stock posé directement (sans opération) : seule façon de créer du stock d'équipement en quantité
     * tant que l'entrée d'équipement crée encore des pièces.
     */
    public static function level(EntityManagerInterface $em, Variant $variant, Location $location, int $quantity, int $unitCostHt, StockCondition $condition = StockCondition::New): StockLevel
    {
        $level = new StockLevel($variant, $location, $condition);
        $level->add($quantity, $quantity * $unitCostHt);
        $em->persist($level);
        $em->flush();

        return $level;
    }
}
