<?php

declare(strict_types=1);

namespace App\Stock;

use App\Catalogue\Ean;
use App\Entity\Location;
use App\Entity\PlayerHolding;
use App\Entity\Product;
use App\Entity\StockLevel;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Pagination\Page;
use Doctrine\ORM\EntityManagerInterface;

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
        $levels = 'EXISTS (SELECT 1 FROM '.StockLevel::class.' xl WHERE xl.variant = v AND xl.quantity > 0'
            .(null !== $location ? ' AND xl.location = :location' : '').')';
        // Sans filtre de lieu, une variante entièrement détenue par des joueurs reste listée.
        $held = null === $location ? ' OR EXISTS (SELECT 1 FROM '.PlayerHolding::class.' xh WHERE xh.variant = v)' : '';

        $qb = $this->em->createQueryBuilder()
            ->select('v', 'p')
            ->from(Variant::class, 'v')
            ->join('v.product', 'p')
            ->where($levels.$held)
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
            $data = $totals[$variant->getId() ?? 0] ?? ['quantity' => 0, 'value' => 0, 'locations' => [], 'loaned' => 0, 'conditions' => []];

            return new StockRow($variant, $data['quantity'], $data['value'], $data['locations'], $data['loaned'], ProductKind::Equipment === $variant->getProduct()?->getKind() ? self::secondHandConditions($data['conditions']) : []);
        }, $items);

        return new Page($rows, $variants->page, $variants->perPage, $variants->total);
    }

    public function totalValueHt(?Location $location): int
    {
        $levels = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(l.valueHt), 0)')
            ->from(StockLevel::class, 'l');
        if (null !== $location) {
            $levels->where('l.location = :location')->setParameter('location', $location);

            return $this->int($levels->getQuery()->getSingleScalarResult());
        }

        // Sans filtre de lieu : le matériel détenu par les joueurs (prêts en cours) compte dans la valeur.
        $held = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(h.valueHt), 0)')
            ->from(PlayerHolding::class, 'h');

        return $this->int($levels->getQuery()->getSingleScalarResult())
            + $this->int($held->getQuery()->getSingleScalarResult());
    }

    /**
     * @return array<int, array{levels: list<StockLevel>, held: int}> id de variante → niveaux > 0 (triés par lieu puis état) et quantité détenue par les joueurs
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
            ->getQuery()->getResult();
        // Par lieu (nom), puis états dans l'ordre Neuf, Bon, Usé, Abîmé.
        $rank = array_flip(array_map(static fn (StockCondition $condition): string => $condition->value, StockCondition::cases()));
        usort($levels, static fn (StockLevel $a, StockLevel $b): int => [$a->getLocation()->getName(), $a->getLocation()->getId(), $rank[$a->getCondition()->value]]
            <=> [$b->getLocation()->getName(), $b->getLocation()->getId(), $rank[$b->getCondition()->value]]);
        foreach ($levels as $level) {
            $result[(int) $level->getVariant()->getId()]['levels'][] = $level;
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
            $result[$id] = ['levels' => $entry['levels'] ?? [], 'held' => $entry['held'] ?? 0];
        }

        return $result;
    }

    /**
     * @param list<int> $variantIds
     *
     * @return array<int, array{quantity: int, value: int, locations: array<int, int>, loaned: int, conditions: array<int, array<string, int>>}>
     */
    private function aggregate(array $variantIds, ?Location $location): array
    {
        $levels = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.variant) AS variant', 'IDENTITY(l.location) AS location', 'l.condition AS cond', 'SUM(l.quantity) AS qty', 'SUM(l.valueHt) AS value')
            ->from(StockLevel::class, 'l')
            ->where('l.quantity > 0 AND l.variant IN (:ids)')
            ->setParameter('ids', $variantIds)
            ->groupBy('l.variant', 'l.location', 'l.condition');
        if (null !== $location) {
            $levels->andWhere('l.location = :location')->setParameter('location', $location);
        }

        $totals = [];
        /** @var list<array{variant: int|string, location: int|string, cond: StockCondition, qty: int|string, value: int|string|null}> $rows */
        $rows = $levels->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $variant = (int) $row['variant'];
            $loc = (int) $row['location'];
            $totals[$variant] ??= ['quantity' => 0, 'value' => 0, 'locations' => [], 'loaned' => 0, 'conditions' => []];
            $totals[$variant]['quantity'] += (int) $row['qty'];
            $totals[$variant]['value'] += (int) $row['value'];
            $totals[$variant]['locations'][$loc] = ($totals[$variant]['locations'][$loc] ?? 0) + (int) $row['qty'];
            $totals[$variant]['conditions'][$loc][$row['cond']->value] = (int) $row['qty'];
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
                $totals[$variant] ??= ['quantity' => 0, 'value' => 0, 'locations' => [], 'loaned' => 0, 'conditions' => []];
                $totals[$variant]['quantity'] += (int) $row['qty'];
                $totals[$variant]['value'] += (int) $row['value'];
                $totals[$variant]['loaned'] += (int) $row['qty'];
            }
        }

        return $totals;
    }

    /**
     * Ne garde que les lieux où l'équipement a de l'occasion ; états dans l'ordre Neuf, Bon, Usé, Abîmé.
     *
     * @param array<int, array<string, int>> $byLocation
     *
     * @return array<int, array<string, int>>
     */
    private static function secondHandConditions(array $byLocation): array
    {
        $result = [];
        foreach ($byLocation as $location => $conditions) {
            if ([] === array_diff_key($conditions, [StockCondition::New->value => true])) {
                continue;
            }
            foreach (StockCondition::cases() as $condition) {
                if (isset($conditions[$condition->value])) {
                    $result[$location][$condition->value] = $conditions[$condition->value];
                }
            }
        }

        return $result;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
