<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\Team;
use Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter;

/**
 * Lecture d'historique : joueurs et équipes supprimés (soft delete) restent chargés, pour que leurs noms
 * s'affichent au lieu d'un EntityNotFoundException au chargement paresseux. Les associations doivent être
 * jointes (fetch-join) dans la requête exécutée par $read.
 */
trait IncludesDeletedPeopleTrait
{
    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function includingDeletedPeople(callable $read): mixed
    {
        $filters = $this->getEntityManager()->getFilters();
        if (!$filters->isEnabled('softdeleteable')) {
            return $read();
        }
        $filter = $filters->getFilter('softdeleteable');
        \assert($filter instanceof SoftDeleteableFilter);
        $filter->disableForEntity(Player::class);
        $filter->disableForEntity(Team::class);

        try {
            return $read();
        } finally {
            $filter->enableForEntity(Player::class);
            $filter->enableForEntity(Team::class);
        }
    }
}
