<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\RosterEntry;
use App\Entity\Season;
use App\Entity\StockMovement;
use App\Entity\Team;
use App\Entity\Variant;
use App\Enum\ItemCondition;
use App\Enum\ItemStatus;
use App\Enum\StockMovementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 */
class ItemRepository extends ServiceEntityRepository
{
    use IncludesDeletedPeopleTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    public function nextCode(): string
    {
        $next = $this->getEntityManager()->getConnection()->fetchOne("SELECT nextval('item_code_seq')");

        return \sprintf('HS-%06d', is_numeric($next) ? (int) $next : throw new \LogicException('item_code_seq indisponible.'));
    }

    /**
     * Pièce par code HS-… (espaces ignorés, insensible à la casse).
     */
    public function findOneByCode(string $code): ?Item
    {
        $item = $this->findOneBy(['code' => strtoupper(trim($code))]);

        return $item instanceof Item ? $item : null;
    }

    /**
     * Pièce par code pour sa fiche : détenteur joint, même supprimé (soft delete).
     */
    public function findOneByCodeWithHolder(string $code): ?Item
    {
        $item = $this->includingDeletedPeople(fn (): mixed => $this->createQueryBuilder('i')
            ->addSelect('h')
            ->leftJoin('i.holder', 'h')
            ->where('i.code = :code')
            ->setParameter('code', strtoupper(trim($code)))
            ->getQuery()
            ->getOneOrNullResult());

        return $item instanceof Item ? $item : null;
    }

    /**
     * Relit la pièce en base avec un verrou PESSIMISTIC_WRITE (état rafraîchi) ; à appeler dans une transaction.
     */
    public function findForUpdate(Item $item): Item
    {
        $fresh = $this->createQueryBuilder('i')
            ->where('i.id = :id')
            ->setParameter('id', $item->getId())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert($fresh instanceof Item);

        return $fresh;
    }

    /**
     * Pièces en stock des variantes données, triées par code (une seule requête).
     *
     * @param list<Variant> $variants
     *
     * @return list<Item>
     */
    public function findInStockByVariants(array $variants): array
    {
        if ([] === $variants) {
            return [];
        }

        /** @var list<Item> $items */
        $items = $this->createQueryBuilder('i')
            ->where('i.variant IN (:variants)')
            ->andWhere('i.status = :status')
            ->setParameter('variants', $variants)
            ->setParameter('status', ItemStatus::InStock)
            ->orderBy('i.code')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * Plus ancienne pièce en stock (plus petit code) de la variante, du lieu et de l'état donnés, verrouillée
     * (PESSIMISTIC_WRITE, état rafraîchi) ; à appeler dans une transaction.
     */
    public function findOldestInStockForUpdate(Variant $variant, Location $location, ItemCondition $condition): ?Item
    {
        $item = $this->createQueryBuilder('i')
            ->where('i.variant = :variant')
            ->andWhere('i.location = :location')
            ->andWhere('i.condition = :condition')
            ->andWhere('i.status = :status')
            ->setParameter('variant', $variant)
            ->setParameter('location', $location)
            ->setParameter('condition', $condition)
            ->setParameter('status', ItemStatus::InStock)
            ->orderBy('i.code')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $item instanceof Item ? $item : null;
    }

    /**
     * Nombre de pièces en stock de la variante dans le lieu, par état.
     *
     * @return array<string, int> clé = valeur d'ItemCondition
     */
    public function countInStockByCondition(Variant $variant, Location $location): array
    {
        /** @var list<array{itemCondition: ItemCondition|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.condition AS itemCondition, COUNT(i.id) AS total')
            ->where('i.variant = :variant')
            ->andWhere('i.location = :location')
            ->andWhere('i.status = :status')
            ->setParameter('variant', $variant)
            ->setParameter('location', $location)
            ->setParameter('status', ItemStatus::InStock)
            ->groupBy('i.condition')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $condition = $row['itemCondition'];
            $counts[$condition instanceof ItemCondition ? $condition->value : $condition] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Pièces prêtées de la variante, détenteur joint, triées par nom de joueur.
     *
     * @return list<Item>
     */
    public function findLoanedByVariant(Variant $variant): array
    {
        /** @var list<Item> $items */
        $items = $this->createQueryBuilder('i')
            ->addSelect('h')
            ->join('i.holder', 'h')
            ->where('i.variant = :variant')
            ->andWhere('i.status = :status')
            ->setParameter('variant', $variant)
            ->setParameter('status', ItemStatus::Loaned)
            ->orderBy('h.lastName')
            ->addOrderBy('h.firstName')
            ->addOrderBy('i.code')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * Pièces prêtées au joueur, triées par code.
     *
     * @return list<Item>
     */
    public function findLoanedByPlayer(Player $player): array
    {
        /** @var list<Item> $items */
        $items = $this->createQueryBuilder('i')
            ->addSelect('v', 'p')
            ->join('i.variant', 'v')
            ->join('v.product', 'p')
            ->where('i.holder = :player')
            ->andWhere('i.status = :status')
            ->setParameter('player', $player)
            ->setParameter('status', ItemStatus::Loaned)
            ->orderBy('i.code')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * Pièces prêtées, détenteur et variante joints, triées par joueur puis code. Avec équipe et/ou saison :
     * seules les pièces dont le détenteur figure dans un effectif correspondant.
     */
    public function loanedQuery(?Team $team, ?Season $season): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('h', 'v', 'p')
            ->join('i.holder', 'h')
            ->join('i.variant', 'v')
            ->join('v.product', 'p')
            ->where('i.status = :status')
            ->setParameter('status', ItemStatus::Loaned)
            ->orderBy('h.lastName')
            ->addOrderBy('h.firstName')
            ->addOrderBy('i.code');

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
            $qb->andWhere($qb->expr()->in('h.id', $sub->getDQL()));
        }

        return $qb;
    }

    /**
     * Date du dernier prêt de chaque pièce (occurredAt du dernier LoanOut), en une requête.
     *
     * @param iterable<Item> $items
     *
     * @return array<int, \DateTimeImmutable> date par identifiant de pièce
     */
    public function loanDates(iterable $items): array
    {
        $list = [...$items];
        if ([] === $list) {
            return [];
        }

        /** @var list<array{id: int|string, since: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(m.item) AS id', 'MAX(o.occurredAt) AS since')
            ->from(StockMovement::class, 'm')
            ->join('m.operation', 'o')
            ->where('m.item IN (:items)')
            ->andWhere('o.type = :type')
            ->setParameter('items', $list)
            ->setParameter('type', StockMovementType::LoanOut)
            ->groupBy('m.item')
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['id']] = new \DateTimeImmutable($row['since']);
        }

        return $dates;
    }
}
