<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\PlayerHolding;
use App\Entity\RosterEntry;
use App\Entity\Season;
use App\Entity\StockMovement;
use App\Entity\Team;
use App\Entity\Variant;
use App\Enum\StockMovementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerHolding>
 */
class PlayerHoldingRepository extends ServiceEntityRepository
{
    use IncludesDeletedPeopleTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerHolding::class);
    }

    /**
     * Verrouille la détention (joueur, variante) en PESSIMISTIC_WRITE ; à appeler dans une transaction.
     */
    public function findForUpdate(Player $player, Variant $variant): ?PlayerHolding
    {
        $holding = $this->createQueryBuilder('h')
            ->where('h.player = :player')
            ->andWhere('h.variant = :variant')
            ->setParameter('player', $player)
            ->setParameter('variant', $variant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert(null === $holding || $holding instanceof PlayerHolding);

        return $holding;
    }

    /**
     * Matériel détenu par un joueur, variante et produit joints.
     *
     * @return list<PlayerHolding>
     */
    public function findByPlayer(Player $player): array
    {
        /** @var list<PlayerHolding> $holdings */
        $holdings = $this->createQueryBuilder('h')
            ->addSelect('v', 'p')
            ->join('h.variant', 'v')
            ->join('v.product', 'p')
            ->where('h.player = :player')
            ->setParameter('player', $player)
            ->orderBy('p.designation')
            ->addOrderBy('v.id')
            ->getQuery()
            ->getResult();

        return $holdings;
    }

    /**
     * Détenteurs d'une variante, joueurs supprimés inclus, triés par nom puis prénom.
     *
     * @return list<PlayerHolding>
     */
    public function findByVariant(Variant $variant): array
    {
        /** @var list<PlayerHolding> $holdings */
        $holdings = $this->includingDeletedPeople(fn (): mixed => $this->createQueryBuilder('h')
            ->addSelect('pl')
            ->join('h.player', 'pl')
            ->where('h.variant = :variant')
            ->setParameter('variant', $variant)
            ->orderBy('pl.lastName')
            ->addOrderBy('pl.firstName')
            ->addOrderBy('pl.id')
            ->getQuery()
            ->getResult());

        return $holdings;
    }

    /**
     * Prêts en cours (une ligne par détention), filtrables par équipe et/ou saison (effectif).
     */
    public function listQuery(?Team $team, ?Season $season): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h')
            ->addSelect('pl', 'v', 'p')
            ->join('h.player', 'pl')
            ->join('h.variant', 'v')
            ->join('v.product', 'p')
            ->orderBy('pl.lastName')
            ->addOrderBy('pl.firstName')
            ->addOrderBy('p.designation')
            ->addOrderBy('v.id');

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
            $qb->andWhere($qb->expr()->in('pl.id', $sub->getDQL()));
        }

        return $qb;
    }

    /**
     * Date du dernier prêt de chaque détention : dernière opération de prêt de ce joueur pour cette variante.
     *
     * @param iterable<PlayerHolding> $holdings
     *
     * @return array<int, \DateTimeImmutable> id de détention => date
     */
    public function loanDates(iterable $holdings): array
    {
        $byKey = [];
        foreach ($holdings as $holding) {
            $byKey[$holding->getPlayer()->getId().'-'.$holding->getVariant()->getId()] = (int) $holding->getId();
        }
        if ([] === $byKey) {
            return [];
        }

        $players = [];
        $variants = [];
        foreach (array_keys($byKey) as $key) {
            [$player, $variant] = explode('-', (string) $key);
            $players[$player] = (int) $player;
            $variants[$variant] = (int) $variant;
        }

        /** @var list<array{player: int|string, variant: int|string, since: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(o.player) AS player', 'IDENTITY(m.variant) AS variant', 'MAX(o.occurredAt) AS since')
            ->from(StockMovement::class, 'm')
            ->join('m.operation', 'o')
            ->where('o.type = :type')
            ->andWhere('IDENTITY(o.player) IN (:players)')
            ->andWhere('IDENTITY(m.variant) IN (:variants)')
            ->setParameter('type', StockMovementType::LoanOut)
            ->setParameter('players', array_values($players))
            ->setParameter('variants', array_values($variants))
            ->groupBy('o.player', 'm.variant')
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        foreach ($rows as $row) {
            $id = $byKey[$row['player'].'-'.$row['variant']] ?? null;
            if (null !== $id) {
                $dates[$id] = new \DateTimeImmutable($row['since']);
            }
        }

        return $dates;
    }

    public function playerHoldsAnything(Player $player): bool
    {
        return null !== $this->createQueryBuilder('h')
            ->select('h.id')
            ->where('h.player = :player')
            ->setParameter('player', $player)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
