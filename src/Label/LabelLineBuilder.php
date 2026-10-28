<?php

declare(strict_types=1);

namespace App\Label;

use App\Entity\Item;
use App\Entity\Product;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockMovementType;
use App\Repository\ItemRepository;
use App\Repository\VariantRepository;
use App\Stock\StockPresence;

/**
 * Construit les lignes d'étiquettes à imprimer. Seules les variantes « étiquetables » (non supprimées, actives ou en stock)
 * sont retenues, y compris celles passées explicitement.
 */
final readonly class LabelLineBuilder
{
    public function __construct(
        private ItemRepository $items,
        private VariantRepository $variants,
        private StockPresence $presence,
    ) {
    }

    /**
     * @param list<Variant> $variants
     *
     * @return list<LabelLine>
     */
    public function forVariants(array $variants, LabelMode $mode): array
    {
        $variants = $this->labelable($variants);
        $itemsByVariant = LabelMode::Items === $mode ? $this->itemsByVariant($variants) : [];

        $lines = [];
        foreach ($variants as $variant) {
            $items = $itemsByVariant[(int) $variant->getId()] ?? [];
            if ([] === $items) {
                $lines[] = new LabelLine($variant, null, 1);
                continue;
            }
            foreach ($items as $item) {
                $lines[] = new LabelLine(null, $item, 1);
            }
        }

        return $lines;
    }

    /**
     * @param list<Product> $products
     *
     * @return list<LabelLine>
     */
    public function forProducts(array $products, LabelMode $mode): array
    {
        $variants = [];
        foreach ($products as $product) {
            array_push($variants, ...$this->variants->findLiveByProduct($product));
        }

        return $this->forVariants($variants, $mode);
    }

    /** @return list<LabelLine> */
    public function forItem(Item $item): array
    {
        return [new LabelLine(null, $item, 1)];
    }

    /** @return list<LabelLine> */
    public function forOperation(StockOperation $operation): array
    {
        if (!\in_array($operation->getType(), [StockMovementType::PurchaseIn, StockMovementType::DonationIn], true)) {
            return [];
        }

        $lines = [];
        /** @var array<int, int> $copies */
        $copies = [];
        /** @var array<int, Variant> $variants */
        $variants = [];
        foreach ($operation->getMovements() as $movement) {
            $item = $movement->getItem();
            if (null !== $item) {
                $lines[] = new LabelLine(null, $item, 1);
                continue;
            }
            $variant = $movement->getVariant();
            $key = (int) $variant->getId();
            $variants[$key] = $variant;
            $copies[$key] = ($copies[$key] ?? 0) + $movement->getQuantity();
        }
        foreach ($this->labelable(array_values($variants)) as $variant) {
            $lines[] = new LabelLine($variant, null, $copies[(int) $variant->getId()]);
        }

        return $lines;
    }

    /** @return list<LabelLine> */
    public function withoutValidEan(): array
    {
        return array_map(
            static fn (Variant $variant): LabelLine => new LabelLine($variant, null, 1),
            $this->variants->findLabelableWithoutValidEan(),
        );
    }

    /** @param list<Variant> $variants */
    public function hasItemChoice(array $variants): bool
    {
        return [] !== $this->itemsByVariant($this->labelable($variants));
    }

    /**
     * @param list<Variant> $variants
     *
     * @return list<Variant>
     */
    private function labelable(array $variants): array
    {
        $variants = array_values(array_filter($variants, static fn (Variant $variant): bool => null === $variant->getDeletedAt()));
        $inactive = [];
        foreach ($variants as $variant) {
            if (!$variant->isActive()) {
                $inactive[] = (int) $variant->getId();
            }
        }
        $inStock = $this->presence->variantIdsInStock($inactive);

        return array_values(array_filter(
            $variants,
            static fn (Variant $variant): bool => $variant->isActive() || isset($inStock[(int) $variant->getId()]),
        ));
    }

    /**
     * Pièces en stock des variantes sérialisées, par id de variante (une requête).
     *
     * @param list<Variant> $variants
     *
     * @return array<int, list<Item>>
     */
    private function itemsByVariant(array $variants): array
    {
        $serialized = array_values(array_filter(
            $variants,
            static fn (Variant $variant): bool => ProductKind::Equipment === $variant->getProduct()?->getKind(),
        ));

        $grouped = [];
        foreach ($this->items->findInStockByVariants($serialized) as $item) {
            $grouped[(int) $item->getVariant()->getId()][] = $item;
        }

        return $grouped;
    }
}
