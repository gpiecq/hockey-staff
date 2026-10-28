<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\RosterEntry;
use App\Entity\TeamSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RosterEntry>
 */
class RosterEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RosterEntry::class);
    }

    /**
     * Entrées de l'effectif dont le joueur n'est pas supprimé (le filtre softdeleteable s'applique à la jointure).
     *
     * @return list<RosterEntry>
     */
    public function findForTeamSeason(TeamSeason $teamSeason): array
    {
        /** @var list<RosterEntry> $entries */
        $entries = $this->createQueryBuilder('re')
            ->join('re.player', 'p')
            ->addSelect('p')
            ->where('re.teamSeason = :teamSeason')
            ->setParameter('teamSeason', $teamSeason)
            ->orderBy('p.lastName')
            ->addOrderBy('p.firstName')
            ->addOrderBy('re.id')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function exists(TeamSeason $teamSeason, Player $player): bool
    {
        return null !== $this->findOneBy(['teamSeason' => $teamSeason, 'player' => $player]);
    }

    /** @return array<int, int|null> numéro par identifiant de joueur */
    public function numbersByPlayer(TeamSeason $teamSeason): array
    {
        $numbers = [];
        foreach ($this->findForTeamSeason($teamSeason) as $entry) {
            $numbers[(int) $entry->getPlayer()->getId()] = $entry->getNumber();
        }

        return $numbers;
    }

    /**
     * Effectifs du joueur, équipe et saison joints, saison la plus récente d'abord.
     *
     * @return list<RosterEntry>
     */
    public function findForPlayer(Player $player): array
    {
        /** @var list<RosterEntry> $entries */
        $entries = $this->createQueryBuilder('re')
            ->join('re.teamSeason', 'ts')
            ->join('ts.team', 't')
            ->join('ts.season', 's')
            ->addSelect('ts', 't', 's')
            ->where('re.player = :player')
            ->setParameter('player', $player)
            ->orderBy('s.startsOn', 'DESC')
            ->addOrderBy('t.name')
            ->getQuery()
            ->getResult();

        return $entries;
    }
}
