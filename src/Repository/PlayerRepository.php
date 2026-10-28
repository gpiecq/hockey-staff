<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
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

    /** Liste triée par nom puis prénom ; recherche insensible aux accents et à la casse sur nom/prénom. */
    public function search(string $query): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.lastName')->addOrderBy('p.firstName')->addOrderBy('p.id');

        $query = trim($query);
        if ('' !== $query) {
            $like = '%'.addcslashes($query, '%_\\').'%';
            $qb->andWhere('LOWER(UNACCENT(p.lastName)) LIKE LOWER(UNACCENT(:like)) OR LOWER(UNACCENT(p.firstName)) LIKE LOWER(UNACCENT(:like))')
                ->setParameter('like', $like);
        }

        return $qb;
    }
}
