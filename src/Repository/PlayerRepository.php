<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\RosterEntry;
use App\Entity\Season;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Player>
 */
class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /** Liste triée par nom puis prénom ; recherche insensible aux accents et à la casse sur nom/prénom ; filtres optionnels sur l'effectif (équipe, saison). */
    public function search(string $query, ?Team $team = null, ?Season $season = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.lastName')->addOrderBy('p.firstName')->addOrderBy('p.id');

        $query = trim($query);
        if ('' !== $query) {
            $like = '%'.addcslashes($query, '%_\\').'%';
            $qb->andWhere('LOWER(UNACCENT(p.lastName)) LIKE LOWER(UNACCENT(:like)) OR LOWER(UNACCENT(p.firstName)) LIKE LOWER(UNACCENT(:like))')
                ->setParameter('like', $like);
        }

        if (null !== $team || null !== $season) {
            $sub = $this->getEntityManager()->createQueryBuilder()
                ->select('IDENTITY(re.player)')
                ->from(RosterEntry::class, 're')
                ->join('re.teamSeason', 'ts');
            if (null !== $team) {
                $sub->andWhere('ts.team = :team');
                $qb->setParameter('team', $team);
            }
            if (null !== $season) {
                $sub->andWhere('ts.season = :season');
                $qb->setParameter('season', $season);
            }
            $qb->andWhere($qb->expr()->in('p.id', $sub->getDQL()));
        }

        return $qb;
    }
}
