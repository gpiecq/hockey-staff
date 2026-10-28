<?php

declare(strict_types=1);

namespace App\Player;

use App\Entity\RosterEntry;
use App\Entity\TeamSeason;
use App\Repository\RosterEntryRepository;
use App\Repository\TeamSeasonRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Reprend dans un effectif les joueurs de la saison précédente de la même équipe. */
final readonly class RosterCopier
{
    public function __construct(
        private TeamSeasonRepository $teamSeasons,
        private RosterEntryRepository $entries,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return int nombre de joueurs ajoutés */
    public function copyFromPrevious(TeamSeason $target): int
    {
        $previous = $this->teamSeasons->findPrevious($target);
        if (null === $previous) {
            return 0;
        }

        $present = [];
        foreach ($this->entries->findForTeamSeason($target) as $entry) {
            $present[(int) $entry->getPlayer()->getId()] = true;
        }

        $added = 0;
        foreach ($this->entries->findForTeamSeason($previous) as $entry) {
            $player = $entry->getPlayer();
            if (isset($present[(int) $player->getId()])) {
                continue;
            }
            $this->em->persist(new RosterEntry($target, $player, $entry->getNumber()));
            ++$added;
        }
        $this->em->flush();

        return $added;
    }
}
