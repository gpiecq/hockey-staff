<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Player;
use App\Entity\RosterEntry;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamSeason;
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

    public static function team(EntityManagerInterface $em, string $name = 'U15'): Team
    {
        $team = (new Team())->setName($name);
        $em->persist($team);
        $em->flush();

        return $team;
    }

    /** Saison « AAAA-AAAA+1 » du 1er juillet au 30 juin ; $current la désigne comme saison courante. */
    public static function season(EntityManagerInterface $em, int $startYear = 2026, bool $current = false): Season
    {
        $season = new Season(
            $startYear.'-'.($startYear + 1),
            new \DateTimeImmutable($startYear.'-07-01'),
            new \DateTimeImmutable(($startYear + 1).'-06-30'),
        );
        $season->setCurrent($current);
        $em->persist($season);
        $em->flush();

        return $season;
    }

    public static function rosterEntry(EntityManagerInterface $em, Team $team, Season $season, Player $player, ?int $number = null): RosterEntry
    {
        $teamSeason = $em->getRepository(TeamSeason::class)->findOneBy(['team' => $team, 'season' => $season]);
        if (null === $teamSeason) {
            $teamSeason = new TeamSeason($team, $season);
            $em->persist($teamSeason);
        }
        $entry = new RosterEntry($teamSeason, $player, $number);
        $em->persist($entry);
        $em->flush();

        return $entry;
    }
}
