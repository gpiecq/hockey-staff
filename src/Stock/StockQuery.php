<?php

declare(strict_types=1);

namespace App\Stock;

use App\Catalogue\Ean;
use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\PlayerHolding;
use App\Entity\Product;
use App\Entity\StockLevel;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Pagination\Page;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter;

/**
 * Lecture du stock : ce que le club possède, où, et pour quelle valeur (coûts d'achat réels, jamais les tarifs).
 */
final readonly class StockQuery
{
    private const int PER_PAGE = 30;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Une ligne par variante en stock. La pagination porte sur les variantes (requête sans agrégat) ;
     * les quantités et valeurs de la page sont ensuite agrégées en SQL, par variante et par lieu.
     *
     * @return Page<StockRow>
     */
    public function rows(?Location $location, ?int $categoryId, ?string $search, int|string|null $page): Page
    {
        $inStock = 'EXISTS (SELECT 1 FROM '.Item::class.' xi WHERE xi.variant = v AND xi.status IN (:statuses)'
            .(null !== $location ? ' AND xi.location = :location' : '').')';
        $levels = 'EXISTS (SELECT 1 FROM '.StockLevel::class.' xl WHERE xl.variant = v AND xl.quantity > 0'
            .(null !== $location ? ' AND xl.location = :location' : '').')';
        // Sans filtre de lieu, une variante entièrement détenue par des joueurs reste listée.
        $held = null === $location ? ' OR EXISTS (SELECT 1 FROM '.PlayerHolding::class.' xh WHERE xh.variant = v)' : '';

        $qb = $this->em->createQueryBuilder()
            ->select('v', 'p')
            ->from(Variant::class, 'v')
            ->join('v.product', 'p')
            ->where($inStock.' OR '.$levels.$held)
            ->setParameter('statuses', $this->statuses($location))
            ->orderBy('p.designation')
            ->addOrderBy('v.size')
            ->addOrderBy('v.fit')
            ->addOrderBy('v.side')
            ->addOrderBy('v.width')
            ->addOrderBy('v.color')
            ->addOrderBy('v.id');

        if (null !== $location) {
            $qb->setParameter('location', $location);
        }

        if (null !== $categoryId) {
            $qb->leftJoin('p.category', 'c')->leftJoin('c.parent', 'cp')->leftJoin('cp.parent', 'cg')
                ->andWhere('c.id = :category OR cp.id = :category OR cg.id = :category')
                ->setParameter('category', $categoryId);
        }

        $search = null === $search ? '' : trim($search);
        if ('' !== $search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $qb->andWhere('LOWER(UNACCENT(p.designation)) LIKE LOWER(UNACCENT(:like)) OR LOWER(p.reference) LIKE LOWER(:like) OR v.ean IN (:ean)')
                ->setParameter('like', $like)
                ->setParameter('ean', Ean::candidates($search));
        }

        $variants = Page::fromQuery($qb, $page, self::PER_PAGE);
        /** @var list<Variant> $items */
        $items = $variants->items;

        $ids = array_values(array_filter(array_map(static fn (Variant $v): ?int => $v->getId(), $items), static fn (?int $id): bool => null !== $id));
        $totals = [] === $ids ? [] : $this->aggregate($ids, $location);

        $rows = array_map(static function (Variant $variant) use ($totals): StockRow {
            $data = $totals[$variant->getId() ?? 0] ?? ['quantity' => 0, 'value' => 0, 'locations' => [], 'loaned' => 0];

            return new StockRow($variant, $data['quantity'], $data['value'], $data['locations'], $data['loaned']);
        }, $items);

        return new Page($rows, $variants->page, $variants->perPage, $variants->total);
    }

    public function totalValueHt(?Location $location): int
    {
        $items = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(i.purchaseCostHt), 0)')
            ->from(Item::class, 'i')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', $this->statuses($location));
        $levels = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(l.valueHt), 0)')
            ->from(StockLevel::class, 'l');
        if (null !== $location) {
            $items->andWhere('i.location = :location')->setParameter('location', $location);
            $levels->where('l.location = :location')->setParameter('location', $location);

            return $this->int($items->getQuery()->getSingleScalarResult()) + $this->int($levels->getQuery()->getSingleScalarResult());
        }

        // Sans filtre de lieu : le matériel détenu par les joueurs (prêts en cours) compte dans la valeur.
        $held = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(h.valueHt), 0)')
            ->from(PlayerHolding::class, 'h');

        return $this->int($items->getQuery()->getSingleScalarResult())
            + $this->int($levels->getQuery()->getSingleScalarResult())
            + $this->int($held->getQuery()->getSingleScalarResult());
    }

    /**
     * @return array<int, array{levels: list<StockLevel>, items: list<Item>, loaned: list<Item>, held: int}> id de variante → niveaux > 0, pièces en stock, pièces prêtées (détenteur joint) et quantité détenue par les joueurs
     */
    public function byVariant(Product $product): array
    {
        $result = [];

        /** @var list<StockLevel> $levels */
        $levels = $this->em->createQueryBuilder()
            ->select('l', 'loc')
            ->from(StockLevel::class, 'l')
            ->join('l.variant', 'v')
            ->join('l.location', 'loc')
            ->where('v.product = :product AND l.quantity > 0')
            ->setParameter('product', $product)
            ->orderBy('loc.name')
            ->getQuery()->getResult();
        foreach ($levels as $level) {
            $result[(int) $level->getVariant()->getId()]['levels'][] = $level;
        }

        /** @var list<Item> $items */
        $items = $this->em->createQueryBuilder()
            ->select('i', 'loc')
            ->from(Item::class, 'i')
            ->join('i.variant', 'v')
            ->join('i.location', 'loc')
            ->where('v.product = :product AND i.status = :inStock')
            ->setParameter('product', $product)
            ->setParameter('inStock', ItemStatus::InStock)
            ->orderBy('i.code')
            ->getQuery()->getResult();
        foreach ($items as $item) {
            $result[(int) $item->getVariant()->getId()]['items'][] = $item;
        }

        foreach ($this->loanedWithHolder($product) as $item) {
            $result[(int) $item->getVariant()->getId()]['loaned'][] = $item;
        }

        /** @var list<array{variant: int|string, held: int|string}> $held */
        $held = $this->em->createQueryBuilder()
            ->select('IDENTITY(h.variant) AS variant', 'SUM(h.quantity) AS held')
            ->from(PlayerHolding::class, 'h')
            ->join('h.variant', 'v')
            ->where('v.product = :product')
            ->setParameter('product', $product)
            ->groupBy('h.variant')
            ->getQuery()->getArrayResult();
        foreach ($held as $row) {
            $result[(int) $row['variant']]['held'] = (int) $row['held'];
        }

        foreach ($result as $id => $entry) {
            $result[$id] = ['levels' => $entry['levels'] ?? [], 'items' => $entry['items'] ?? [], 'loaned' => $entry['loaned'] ?? [], 'held' => $entry['held'] ?? 0];
        }

        return $result;
    }

    /**
     * Pièces prêtées du produit, détenteur joint même supprimé (soft delete).
     *
     * @return list<Item>
     */
    private function loanedWithHolder(Product $product): array
    {
        $filters = $this->em->getFilters();
        $filter = $filters->isEnabled('softdeleteable') ? $filters->getFilter('softdeleteable') : null;
        \assert(null === $filter || $filter instanceof SoftDeleteableFilter);
        $filter?->disableForEntity(Player::class);

        try {
            /** @var list<Item> $loaned */
            $loaned = $this->em->createQueryBuilder()
                ->select('i', 'h')
                ->from(Item::class, 'i')
                ->join('i.variant', 'v')
                ->leftJoin('i.holder', 'h')
                ->where('v.product = :product AND i.status = :loaned')
                ->setParameter('product', $product)
                ->setParameter('loaned', ItemStatus::Loaned)
                ->orderBy('i.code')
                ->getQuery()->getResult();
        } finally {
            $filter?->enableForEntity(Player::class);
        }

        return $loaned;
    }

    /**
     * Statuts comptés : le physique seul pour un lieu, les pièces prêtées en plus sans filtre de lieu.
     *
     * @return list<ItemStatus>
     */
    private function statuses(?Location $location): array
    {
        return null === $location ? [ItemStatus::InStock, ItemStatus::Loaned] : [ItemStatus::InStock];
    }

    /**
     * @param list<int> $variantIds
     *
     * @return array<int, array{quantity: int, value: int, locations: array<int, int>, loaned: int}>
     */
    private function aggregate(array $variantIds, ?Location $location): array
    {
        $items = $this->em->createQueryBuilder()
            ->select('IDENTITY(i.variant) AS variant', 'IDENTITY(i.location) AS location', 'i.status AS status', 'COUNT(i.id) AS qty', 'SUM(i.purchaseCostHt) AS value')
            ->from(Item::class, 'i')
            ->where('i.status IN (:statuses) AND i.variant IN (:ids)')
            ->setParameter('statuses', $this->statuses($location))
            ->setParameter('ids', $variantIds)
            ->groupBy('i.variant', 'i.location', 'i.status');
        $levels = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.variant) AS variant', 'IDENTITY(l.location) AS location', 'SUM(l.quantity) AS qty', 'SUM(l.valueHt) AS value')
            ->from(StockLevel::class, 'l')
            ->where('l.quantity > 0 AND l.variant IN (:ids)')
            ->setParameter('ids', $variantIds)
            ->groupBy('l.variant', 'l.location');
        if (null !== $location) {
            $items->andWhere('i.location = :location')->setParameter('location', $location);
            $levels->andWhere('l.location = :location')->setParameter('location', $location);
        }

        $totals = [];
        foreach ([$items, $levels] as $query) {
            /** @var list<array{variant: int|string, location: int|string, status?: ItemStatus, qty: int|string, value: int|string|null}> $rows */
            $rows = $query->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $variant = (int) $row['variant'];
                $loc = (int) $row['location'];
                $totals[$variant] ??= ['quantity' => 0, 'value' => 0, 'locations' => [], 'loaned' => 0];
                $totals[$variant]['quantity'] += (int) $row['qty'];
                $totals[$variant]['value'] += (int) $row['value'];
                if (ItemStatus::Loaned === ($row['status'] ?? null)) {
                    $totals[$variant]['loaned'] += (int) $row['qty'];
                } else {
                    $totals[$variant]['locations'][$loc] = ($totals[$variant]['locations'][$loc] ?? 0) + (int) $row['qty'];
                }
            }
        }

        // Sans filtre de lieu : les quantités détenues par les joueurs comptent comme prêtées.
        if (null === $location) {
            /** @var list<array{variant: int|string, qty: int|string, value: int|string}> $held */
            $held = $this->em->createQueryBuilder()
                ->select('IDENTITY(h.variant) AS variant', 'SUM(h.quantity) AS qty', 'SUM(h.valueHt) AS value')
                ->from(PlayerHolding::class, 'h')
                ->where('h.variant IN (:ids)')
                ->setParameter('ids', $variantIds)
                ->groupBy('h.variant')
                ->getQuery()->getArrayResult();
            foreach ($held as $row) {
                $variant = (int) $row['variant'];
                $totals[$variant] ??= ['quantity' => 0, 'value' => 0, 'locations' => [], 'loaned' => 0];
                $totals[$variant]['quantity'] += (int) $row['qty'];
                $totals[$variant]['value'] += (int) $row['value'];
                $totals[$variant]['loaned'] += (int) $row['qty'];
            }
        }

        return $totals;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
