<?php

declare(strict_types=1);

namespace App\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Item;
use App\Entity\Location;
use App\Entity\StockLevel;
use App\Entity\StockMovement;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\StockMovementType;
use App\Enum\TrackingMode;
use App\Repository\ItemRepository;
use App\Repository\StockLevelRepository;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'entrée unique de toute variation de stock.
 */
final readonly class StockMovementRecorder
{
    public function __construct(
        private EntityManagerInterface $em,
        private StockLevelRepository $levels,
        private ItemRepository $items,
        private EntryCostProvider $costs,
        private CurrentSeasonProvider $seasons,
    ) {
    }

    public function recordEntry(StockEntry $entry): StockOperation
    {
        $isPurchase = StockMovementType::PurchaseIn === $entry->type;

        if ([] === $entry->lines) {
            throw new StockException('Ajoutez au moins une ligne.');
        }
        if (!$entry->location->isActive()) {
            throw new StockException(\sprintf('Le lieu « %s » est inactif.', $entry->location->getName()));
        }

        // Tout est contrôlé avant la première écriture.
        $plans = [];
        foreach ($entry->lines as $line) {
            $plans[] = $this->planLine($line, $isPurchase);
        }
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($entry, $plans, $season): StockOperation {
            $operation = new StockOperation($entry->type, null, $entry->location, null, $entry->documentReference, $entry->comment);
            $this->em->persist($operation);
            /** @var array<int, StockLevel> $levels */
            $levels = [];

            foreach ($plans as $plan) {
                $line = $plan['line'];
                $variant = $line->variant;

                if (TrackingMode::Serialized === $variant->getProduct()?->getTrackingMode()) {
                    for ($i = 0; $i < $line->quantity; ++$i) {
                        $item = new Item($this->items->nextCode(), $variant, $entry->location, $plan['cost'], $line->condition, $plan['serials'][$i] ?? null);
                        $this->em->persist($item);
                        $operation->addMovement(new StockMovement($operation, $variant, 1, $plan['cost'], $item, $plan['catalogueValue'], $season));
                    }
                    continue;
                }

                $key = (int) $variant->getId();
                $level = $levels[$key] ??= $this->levels->findForUpdate($variant, $entry->location) ?? $this->newLevel($variant, $entry->location);
                $level->add($line->quantity, $line->quantity * $plan['cost']);
                $operation->addMovement(new StockMovement($operation, $variant, $line->quantity, $plan['cost'], null, $plan['catalogueValue'], $season));
            }

            $this->em->flush();

            return $operation;
        });
    }

    /**
     * @return array{line: StockEntryLine, cost: int, catalogueValue: ?int, serials: list<string>}
     */
    private function planLine(StockEntryLine $line, bool $isPurchase): array
    {
        $variant = $line->variant;
        if ($variant->isArchived() || !$variant->isActive() || null !== $variant->getDeletedAt()) {
            throw new StockException(\sprintf('« %s » n\'est plus au catalogue : entrée impossible.', $this->label($variant)));
        }
        if ($line->quantity < 1) {
            throw new StockException('La quantité doit être au moins 1.');
        }

        $clubPrice = $this->costs->clubPriceHt($variant);
        if ($isPurchase) {
            $cost = $line->unitCostHt ?? $clubPrice
                ?? throw new StockException(\sprintf('Coût unitaire manquant pour « %s » : aucun tarif club pour la saison courante.', $this->label($variant)));
            if ($cost < 0) {
                throw new StockException('Le coût unitaire ne peut pas être négatif.');
            }
        } else {
            $cost = 0;
        }

        $serials = [];
        if (TrackingMode::Serialized === $variant->getProduct()?->getTrackingMode()) {
            $serials = array_values(array_filter(
                array_map(trim(...), $line->serialNumbers),
                static fn (string $serial): bool => '' !== $serial,
            ));
            if (\count($serials) > $line->quantity) {
                throw new StockException(\sprintf('Plus de numéros de série que de pièces pour « %s ».', $this->label($variant)));
            }
        }

        return ['line' => $line, 'cost' => $cost, 'catalogueValue' => $isPurchase ? null : $clubPrice, 'serials' => $serials];
    }

    private function newLevel(Variant $variant, Location $location): StockLevel
    {
        $level = new StockLevel($variant, $location);
        $this->em->persist($level);

        return $level;
    }

    /**
     * Libellé d'une variante dans les messages : référence produit + libellé de variante.
     */
    private function label(Variant $variant): string
    {
        return trim(($variant->getProduct()?->getReference() ?? '').' '.$variant->getLabel());
    }
}
