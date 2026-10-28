<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Player;
use App\Enum\PlayerPosition;
use Doctrine\ORM\EntityManagerInterface;

final class PlayerFixtures
{
    public static function player(
        EntityManagerInterface $em,
        string $lastName = 'Martin',
        string $firstName = 'Léo',
        PlayerPosition $position = PlayerPosition::Forward,
    ): Player {
        $player = (new Player())->setLastName($lastName)->setFirstName($firstName)->setPosition($position);
        $em->persist($player);
        $em->flush();

        return $player;
    }
}
