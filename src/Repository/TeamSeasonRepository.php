<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamSeason>
 */
class TeamSeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamSeason::class);
    }

    public function findOneFor(Team $team, Season $season): ?TeamSeason
    {
        return $this->findOneBy(['team' => $team, 'season' => $season]);
    }

    /** Persist sans flush : l'appelant flush. */
    public function findOrCreate(Team $team, Season $season): TeamSeason
    {
        $teamSeason = $this->findOneFor($team, $season);
        if (null === $teamSeason) {
            $teamSeason = new TeamSeason($team, $season);
            $this->getEntityManager()->persist($teamSeason);
        }

        return $teamSeason;
    }

    /** Saison précédente de l'équipe : startsOn le plus grand, strictement antérieur à celui de la cible. */
    public function findPrevious(TeamSeason $target): ?TeamSeason
    {
        $result = $this->createQueryBuilder('ts')
            ->join('ts.season', 's')
            ->where('ts.team = :team')
            ->andWhere('s.startsOn < :startsOn')
            ->setParameter('team', $target->getTeam())
            ->setParameter('startsOn', $target->getSeason()->getStartsOn())
            ->orderBy('s.startsOn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert(null === $result || $result instanceof TeamSeason);

        return $result;
    }
}
