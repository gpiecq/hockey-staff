<?php

declare(strict_types=1);

namespace App\Label;

use App\Entity\Product;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\StockMovementType;
use App\Repository\VariantRepository;
use App\Stock\StockPresence;

/**
 * Construit les lignes d'étiquettes à imprimer. Seules les variantes « étiquetables » (non supprimées, actives ou en stock)
 * sont retenues, y compris celles passées explicitement.
 */
final readonly class LabelLineBuilder
{
    public function __construct(
        private VariantRepository $variants,
        private StockPresence $presence,
    ) {
    }

    /**
     * @param list<Variant> $variants
     *
     * @return list<LabelLine>
     */
    public function forVariants(array $variants): array
    {
        return array_map(
            static fn (Variant $variant): LabelLine => new LabelLine($variant, 1),
            $this->labelable($variants),
        );
    }

    /**
     * @param list<Product> $products
     *
     * @return list<LabelLine>
     */
    public function forProducts(array $products): array
    {
        $variants = [];
        foreach ($products as $product) {
            array_push($variants, ...$this->variants->findLiveByProduct($product));
        }

        return $this->forVariants($variants);
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
            $variant = $movement->getVariant();
            $key = (int) $variant->getId();
            $variants[$key] = $variant;
            $copies[$key] = ($copies[$key] ?? 0) + $movement->getQuantity();
        }
        foreach ($this->labelable(array_values($variants)) as $variant) {
            $lines[] = new LabelLine($variant, $copies[(int) $variant->getId()]);
        }

        return $lines;
    }

    /** @return list<LabelLine> */
    public function withoutValidEan(): array
    {
        return array_map(
            static fn (Variant $variant): LabelLine => new LabelLine($variant, 1),
            $this->variants->findLabelableWithoutValidEan(),
        );
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
}
