<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Location;
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
}
