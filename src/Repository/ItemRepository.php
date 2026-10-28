<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\StockCondition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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
     * Pièces d'occasion (état autre que neuf) en stock, tous lieux, triées par code ; variante, produit et lieu joints.
     *
     * @return list<Item>
     */
    public function findSecondHandInStock(): array
    {
        /** @var list<Item> $items */
        $items = $this->createQueryBuilder('i')
            ->addSelect('v', 'p', 'l')
            ->join('i.variant', 'v')
            ->join('v.product', 'p')
            ->join('i.location', 'l')
            ->where('i.status = :status')
            ->andWhere('i.condition <> :new')
            ->setParameter('status', ItemStatus::InStock)
            ->setParameter('new', StockCondition::New)
            ->orderBy('i.code')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * Plus ancienne pièce en stock (plus petit code) de la variante, du lieu et de l'état donnés, verrouillée
     * (PESSIMISTIC_WRITE, état rafraîchi) ; à appeler dans une transaction.
     */
    public function findOldestInStockForUpdate(Variant $variant, Location $location, StockCondition $condition): ?Item
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
     * @return array<string, int> clé = valeur d'StockCondition
     */
    public function countInStockByCondition(Variant $variant, Location $location): array
    {
        /** @var list<array{itemCondition: StockCondition|string, total: int|string}> $rows */
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
            $counts[$condition instanceof StockCondition ? $condition->value : $condition] = (int) $row['total'];
        }

        return $counts;
    }
}
