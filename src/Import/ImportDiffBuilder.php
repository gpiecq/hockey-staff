<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\ImportAnomalyCode;

/**
 * Accumulateur mutable utilisé par CatalogueImportAnalyzer.
 *
 * @phpstan-import-type NewProduct from ImportDiff
 * @phpstan-import-type NewVariant from ImportDiff
 * @phpstan-import-type ChangedVariant from ImportDiff
 * @phpstan-import-type PriceChange from ImportDiff
 * @phpstan-import-type Anomaly from ImportDiff
 */
final class ImportDiffBuilder
{
    /** @var list<NewProduct> */
    private array $newProducts = [];
    /** @var list<NewVariant> */
    private array $newVariants = [];
    /** @var list<ChangedVariant> */
    private array $changedVariants = [];
    /** @var list<PriceChange> */
    private array $priceChanges = [];
    /** @var list<int> */
    private array $deactivated = [];
    /** @var list<int> */
    private array $reactivated = [];
    /** @var list<int> */
    private array $rejectedLines = [];
    /** @var list<Anomaly> */
    private array $anomalies = [];

    public function anomaly(int $line, ImportAnomalyCode $code, string $message): void
    {
        $this->anomalies[] = ['line' => $line, 'code' => $code->value, 'message' => $message];
    }

    public function reject(int $line): void
    {
        $this->rejectedLines[] = $line;
    }

    public function rejectWith(int $line, ImportAnomalyCode $code, string $message): void
    {
        $this->reject($line);
        $this->anomaly($line, $code, $message);
    }

    public function newProduct(NormalizedRow $row): void
    {
        $this->newProducts[] = [
            'line' => $row->line,
            'reference' => $row->reference,
            'designation' => $row->designation,
            'brand' => $row->brand,
            'categoryPath' => $row->categoryPath,
        ];
    }

    public function newVariant(NormalizedRow $row, ?int $productId): void
    {
        $this->newVariants[] = [
            'line' => $row->line,
            'reference' => $row->reference,
            'productId' => $productId,
            'ean' => $row->ean,
            'size' => $row->size,
            'fit' => $row->fit,
            'side' => $row->side,
            'width' => $row->width,
            'color' => $row->color,
            'clubPriceTtc' => $row->clubPriceTtc,
            'publicPriceTtc' => $row->publicPriceTtc,
            'designation' => $row->designation,
            'brand' => $row->brand,
            'categoryPath' => $row->categoryPath,
        ];
    }

    /** @param array<string, array{from: string|null, to: string|null}> $changes */
    public function changed(int $variantId, int $line, array $changes): void
    {
        $this->changedVariants[] = ['variantId' => $variantId, 'line' => $line, 'changes' => $changes];
    }

    /** Un prix nul du fichier ne remplace jamais le prix stocké (R20). */
    public function price(?int $variantId, NormalizedRow $row, ?int $storedClub, ?int $storedPublic): void
    {
        $club = $row->clubPriceTtc ?? $storedClub;
        $public = $row->publicPriceTtc ?? $storedPublic;
        if ($club === $storedClub && $public === $storedPublic) {
            return;
        }
        $this->priceChanges[] = [
            'variantId' => $variantId,
            'line' => $row->line,
            'club' => ['from' => $storedClub, 'to' => $club],
            'public' => ['from' => $storedPublic, 'to' => $public],
        ];
    }

    public function deactivate(int $variantId): void
    {
        $this->deactivated[] = $variantId;
    }

    public function reactivate(int $variantId): void
    {
        $this->reactivated[] = $variantId;
    }

    public function build(): ImportDiff
    {
        return new ImportDiff(
            $this->newProducts,
            $this->newVariants,
            $this->changedVariants,
            $this->priceChanges,
            $this->deactivated,
            $this->reactivated,
            $this->rejectedLines,
            $this->anomalies,
        );
    }
}
