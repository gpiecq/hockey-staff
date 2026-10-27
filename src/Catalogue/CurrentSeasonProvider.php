<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Entity\Season;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CurrentSeasonProvider
{
    public function __construct(
        private SeasonRepository $seasons,
        private EntityManagerInterface $em,
    ) {
    }

    public function get(): ?Season
    {
        return $this->seasons->findOneBy(['isCurrent' => true]);
    }

    /**
     * Une seule saison courante : réinitialise toutes les autres (la cible est exclue du reset : idempotent si déjà courante) puis active celle-ci, en une transaction.
     */
    public function makeCurrent(Season $season): void
    {
        $this->em->wrapInTransaction(function () use ($season): void {
            $this->em->createQueryBuilder()
                ->update(Season::class, 's')
                ->set('s.isCurrent', ':no')
                ->where('s.isCurrent = :yes')
                ->andWhere('s != :season')
                ->setParameter('season', $season)
                ->setParameter('no', false)
                ->setParameter('yes', true)
                ->getQuery()
                ->execute();

            $season->setCurrent(true);
            $this->em->flush();
        });
    }
}
