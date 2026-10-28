<?php

declare(strict_types=1);

namespace App\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Item;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\PlayerHolding;
use App\Entity\Sale;
use App\Entity\SaleLine;
use App\Entity\StockLevel;
use App\Entity\StockMovement;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ItemStatus;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Enum\StockOutReason;
use App\Repository\ItemRepository;
use App\Repository\PlayerHoldingRepository;
use App\Repository\StockLevelRepository;
use App\Sale\SalePricing;
use App\Stock\Input\StockEntry;
use App\Stock\Input\StockEntryLine;
use App\Stock\Input\StockGift;
use App\Stock\Input\StockLine;
use App\Stock\Input\StockLoan;
use App\Stock\Input\StockOut;
use App\Stock\Input\StockReturn;
use App\Stock\Input\StockSale;
use App\Stock\Input\StockTransfer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'entrée unique de toute variation de stock.
 */
final readonly class StockMovementRecorder
{
    public function __construct(
        private EntityManagerInterface $em,
        private StockLevelRepository $levels,
        private PlayerHoldingRepository $holdings,
        private ItemRepository $items,
        private EntryCostProvider $costs,
        private CurrentSeasonProvider $seasons,
        private SalePricing $pricing,
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

                if (ProductKind::Equipment === $variant->getProduct()?->getKind()) {
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

    public function recordOut(StockOut $out): StockOperation
    {
        if (StockOutReason::Other === $out->reason && '' === trim($out->comment ?? '')) {
            throw new StockException('Précisez le motif en commentaire.');
        }
        $this->assertLines($out->lines);
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($out, $season): StockOperation {
            $locked = $this->lockAndCheck($out->location, $out->lines);
            $operation = new StockOperation(StockMovementType::Out, $out->location, null, $out->reason, null, $out->comment);
            $this->em->persist($operation);

            foreach ($out->lines as $line) {
                if (null !== $line->item) {
                    $item = $locked['items'][(int) $line->item->getId()];
                    $item->remove();
                    $operation->addMovement(new StockMovement($operation, $line->variant, 1, $item->getPurchaseCostHt(), $item, null, $season));
                    continue;
                }
                $value = $locked['levels'][(int) $line->variant->getId()]->remove($line->quantity);
                $operation->addMovement(new StockMovement($operation, $line->variant, $line->quantity, (int) round($value / $line->quantity), null, null, $season));
            }

            $this->em->flush();

            return $operation;
        });
    }

    public function recordTransfer(StockTransfer $transfer): StockOperation
    {
        if ($transfer->from->getId() === $transfer->to->getId()) {
            throw new StockException('Les lieux de départ et d\'arrivée doivent être différents.');
        }
        if (!$transfer->to->isActive()) {
            throw new StockException(\sprintf('Le lieu « %s » est inactif.', $transfer->to->getName()));
        }
        $this->assertLines($transfer->lines);
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($transfer, $season): StockOperation {
            $locked = $this->lockAndCheck($transfer->from, $transfer->lines);
            $operation = new StockOperation(StockMovementType::Transfer, $transfer->from, $transfer->to, null, null, $transfer->comment);
            $this->em->persist($operation);
            $destinations = [];

            foreach ($transfer->lines as $line) {
                if (null !== $line->item) {
                    $item = $locked['items'][(int) $line->item->getId()];
                    $item->moveTo($transfer->to);
                    $operation->addMovement(new StockMovement($operation, $line->variant, 1, $item->getPurchaseCostHt(), $item, null, $season));
                    continue;
                }
                $key = (int) $line->variant->getId();
                $value = $locked['levels'][$key]->remove($line->quantity);
                $destination = $destinations[$key] ??= $this->levels->findForUpdate($line->variant, $transfer->to) ?? $this->newLevel($line->variant, $transfer->to);
                $destination->add($line->quantity, $value);
                $operation->addMovement(new StockMovement($operation, $line->variant, $line->quantity, (int) round($value / $line->quantity), null, null, $season));
            }

            $this->em->flush();

            return $operation;
        });
    }

    public function recordLoan(StockLoan $loan): StockOperation
    {
        if (ProductKind::Equipment !== $loan->variant->getProduct()?->getKind()) {
            throw new StockException(\sprintf('« %s » est un consommable : il ne se prête pas.', $this->label($loan->variant)));
        }
        if ($loan->quantity < 1) {
            throw new StockException('La quantité doit être au moins 1.');
        }
        if (!$loan->location->isActive()) {
            throw new StockException(\sprintf('Le lieu « %s » est inactif.', $loan->location->getName()));
        }
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($loan, $season): StockOperation {
            // Verrous (niveau puis détention) et contrôles avant la première écriture.
            $level = $this->levels->findForUpdate($loan->variant, $loan->location, $loan->condition);
            if (null === $level || $loan->quantity > $level->getQuantity()) {
                throw $this->shortStock($loan->variant, $loan->condition, $loan->location, $level?->getQuantity() ?? 0);
            }
            $holding = $this->holdings->findForUpdate($loan->player, $loan->variant) ?? $this->newHolding($loan->player, $loan->variant);

            $operation = new StockOperation(StockMovementType::LoanOut, $loan->location, null, null, null, $loan->comment, $loan->player);
            $this->em->persist($operation);
            $value = $level->remove($loan->quantity);
            $holding->add($loan->quantity, $value);
            $operation->addMovement(new StockMovement($operation, $loan->variant, $loan->quantity, (int) round($value / $loan->quantity), null, null, $season, $loan->condition));

            $this->em->flush();

            return $operation;
        });
    }

    public function recordReturn(StockReturn $return): StockOperation
    {
        if ($return->quantity < 1) {
            throw new StockException('La quantité doit être au moins 1.');
        }
        if (!$return->location->isActive()) {
            throw new StockException(\sprintf('Le lieu « %s » est inactif.', $return->location->getName()));
        }
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($return, $season): StockOperation {
            // Verrous (niveau puis détention) et contrôles avant la première écriture.
            $level = $this->levels->findForUpdate($return->variant, $return->location, $return->condition);
            $holding = $this->holdings->findForUpdate($return->player, $return->variant);
            $held = $holding?->getQuantity() ?? 0;
            if (null === $holding || $return->quantity > $held) {
                throw new StockException(\sprintf('%s détient %d « %s » : retour de %d impossible.', $return->player->getFullName(), $held, $this->label($return->variant), $return->quantity));
            }

            $operation = new StockOperation(StockMovementType::LoanReturn, null, $return->location, null, null, $return->comment, $return->player);
            $this->em->persist($operation);
            $value = $holding->remove($return->quantity);
            ($level ?? $this->newLevel($return->variant, $return->location, $return->condition))->add($return->quantity, $value);
            $operation->addMovement(new StockMovement($operation, $return->variant, $return->quantity, (int) round($value / $return->quantity), null, null, $season, $return->condition));
            // Détention soldée : supprimée, la trace reste dans les mouvements.
            if ($return->quantity === $held) {
                $this->em->remove($holding);
            }

            $this->em->flush();

            return $operation;
        });
    }

    public function recordGift(StockGift $gift): StockOperation
    {
        if ((null === $gift->player) === (null === $gift->team)) {
            throw new StockException('Choisissez un joueur ou une équipe.');
        }
        if ($gift->quantity < 1) {
            throw new StockException('La quantité doit être au moins 1.');
        }
        // État de la pièce à donner ; null pour un produit suivi en quantité.
        $condition = null;
        if (ProductKind::Equipment === $gift->variant->getProduct()?->getKind()) {
            $condition = $gift->condition ?? throw new StockException('Choisissez l\'état de la pièce.');
            if (1 !== $gift->quantity) {
                throw new StockException('Une pièce se donne à l\'unité : quantité 1.');
            }
        }
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($gift, $condition, $season): StockOperation {
            // Verrous et contrôles avant la première écriture.
            $item = null;
            $level = null;
            if (null !== $condition) {
                $item = $this->lockOldestInStock($gift->variant, $gift->location, $condition);
            } else {
                $level = $this->lockAndCheck($gift->location, [new StockLine($gift->variant, $gift->quantity)])['levels'][(int) $gift->variant->getId()];
            }
            $operation = new StockOperation(StockMovementType::GiftOut, $gift->location, null, null, null, $gift->comment, $gift->player, $gift->team);
            $this->em->persist($operation);

            if (null !== $item) {
                $item->gift();
                $operation->addMovement(new StockMovement($operation, $gift->variant, 1, $item->getPurchaseCostHt(), $item, null, $season));
            } else {
                $value = $level->remove($gift->quantity);
                $operation->addMovement(new StockMovement($operation, $gift->variant, $gift->quantity, (int) round($value / $gift->quantity), null, null, $season));
            }

            $this->em->flush();

            return $operation;
        });
    }

    public function recordSale(StockSale $sale): StockOperation
    {
        $buyer = trim($sale->buyer);
        if ('' === $buyer) {
            throw new StockException('Indiquez l\'acheteur.');
        }
        if ([] === $sale->lines) {
            throw new StockException('Ajoutez au moins une ligne.');
        }
        // Lignes indexées par id de pièce, dans l'ordre de verrouillage (id croissant) pour éviter les deadlocks.
        $lines = [];
        foreach ($sale->lines as $line) {
            $key = (int) $line->item->getId();
            if (isset($lines[$key])) {
                throw new StockException(\sprintf('La pièce %s figure deux fois.', $line->item->getCode()));
            }
            $lines[$key] = $line;
        }
        ksort($lines);
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($sale, $buyer, $lines, $season): StockOperation {
            // Verrous, contrôles et prix avant la première écriture.
            $plans = [];
            foreach ($lines as $line) {
                $item = $this->items->findForUpdate($line->item);
                if (ItemStatus::InStock !== $item->getStatus() || $item->getLocation()->getId() !== $sale->location->getId()) {
                    throw new StockException(\sprintf('La pièce %s n\'est pas en stock à « %s ».', $item->getCode(), $sale->location->getName()));
                }
                if (StockCondition::New === $item->getCondition()) {
                    throw new StockException(\sprintf('La pièce %s est neuve : seule l\'occasion se vend.', $item->getCode()));
                }
                $plans[] = [$item, $this->pricing->price($item->getVariant(), $line->discountRate, $line->finalPriceTtc)];
            }

            $record = new Sale($buyer, $sale->player, $sale->comment);
            $this->em->persist($record);
            $operation = new StockOperation(StockMovementType::SaleOut, $sale->location, null, null, null, null, $sale->player, null, $record);
            $this->em->persist($operation);

            foreach ($plans as [$item, $price]) {
                $record->addLine(new SaleLine($record, $item, $price));
                $operation->addMovement(new StockMovement($operation, $item->getVariant(), 1, $item->getPurchaseCostHt(), $item, null, $season));
                $item->sell();
            }

            $this->em->flush();

            return $operation;
        });
    }

    /**
     * Contrôles de forme des lignes (sans accès base).
     *
     * @param list<StockLine> $lines
     */
    private function assertLines(array $lines): void
    {
        if ([] === $lines) {
            throw new StockException('Ajoutez au moins une ligne.');
        }

        $codes = [];
        foreach ($lines as $line) {
            if ($line->quantity < 1) {
                throw new StockException('La quantité doit être au moins 1.');
            }
            if (ProductKind::Equipment !== $line->variant->getProduct()?->getKind()) {
                if (null !== $line->item) {
                    throw new StockException(\sprintf('« %s » est suivi en quantité : n\'indiquez pas de pièce.', $this->label($line->variant)));
                }
                continue;
            }
            if (null === $line->item) {
                throw new StockException(\sprintf('Indiquez la pièce (code HS-) pour « %s ».', $this->label($line->variant)));
            }
            if ($line->item->getVariant()->getId() !== $line->variant->getId()) {
                throw new StockException(\sprintf('La pièce %s ne correspond pas à « %s ».', $line->item->getCode(), $this->label($line->variant)));
            }
            if (1 !== $line->quantity) {
                throw new StockException(\sprintf('La quantité de la pièce %s doit être 1.', $line->item->getCode()));
            }
            if (isset($codes[$line->item->getCode()])) {
                throw new StockException(\sprintf('La pièce %s figure deux fois.', $line->item->getCode()));
            }
            $codes[$line->item->getCode()] = true;
        }
    }

    /**
     * Verrouille (PESSIMISTIC_WRITE) les niveaux et pièces visés, puis contrôle la disponibilité ; à appeler dans la transaction.
     *
     * @param list<StockLine> $lines
     *
     * @return array{levels: array<int, StockLevel>, items: array<int, Item>}
     */
    private function lockAndCheck(Location $from, array $lines): array
    {
        $wanted = [];
        $variants = [];
        $items = [];
        foreach ($lines as $line) {
            if (null !== $line->item) {
                $items[(int) $line->item->getId()] = $line->item;
                continue;
            }
            $key = (int) $line->variant->getId();
            $wanted[$key] = ($wanted[$key] ?? 0) + $line->quantity;
            $variants[$key] = $line->variant;
        }

        // Ordre de verrouillage stable (identifiant croissant) pour éviter les deadlocks entre saisies concurrentes.
        ksort($variants);
        ksort($items);

        $levels = [];
        foreach ($variants as $key => $variant) {
            $level = $this->levels->findForUpdate($variant, $from);
            if (null === $level || $wanted[$key] > $level->getQuantity()) {
                throw new StockException(\sprintf('Stock insuffisant pour « %s » à « %s » : %d disponible(s).', $this->label($variant), $from->getName(), $level?->getQuantity() ?? 0));
            }
            $levels[$key] = $level;
        }

        $locked = [];
        foreach ($items as $key => $item) {
            $fresh = $this->items->findForUpdate($item);
            if (ItemStatus::InStock !== $fresh->getStatus() || $fresh->getLocation()->getId() !== $from->getId()) {
                throw new StockException(\sprintf('La pièce %s n\'est pas en stock à « %s ».', $fresh->getCode(), $from->getName()));
            }
            $locked[$key] = $fresh;
        }

        return ['levels' => $levels, 'items' => $locked];
    }

    /**
     * Verrouille la plus ancienne pièce en stock de l'état demandé dans le lieu ; à appeler dans la transaction.
     */
    private function lockOldestInStock(Variant $variant, Location $location, StockCondition $condition): Item
    {
        return $this->items->findOldestInStockForUpdate($variant, $location, $condition)
            ?? throw new StockException(\sprintf('Aucune pièce « %s » à l\'état %s disponible à « %s ».', $this->label($variant), $condition->label(), $location->getName()));
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
        if (ProductKind::Equipment === $variant->getProduct()?->getKind()) {
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

    private function newLevel(Variant $variant, Location $location, StockCondition $condition = StockCondition::New): StockLevel
    {
        $level = new StockLevel($variant, $location, $condition);
        $this->em->persist($level);

        return $level;
    }

    private function newHolding(Player $player, Variant $variant): PlayerHolding
    {
        $holding = new PlayerHolding($player, $variant);
        $this->em->persist($holding);

        return $holding;
    }

    private function shortStock(Variant $variant, StockCondition $condition, Location $location, int $available): StockException
    {
        return new StockException(\sprintf('Stock insuffisant pour « %s » (%s) à « %s » : %d disponible(s).', $this->label($variant), $condition->label(), $location->getName(), $available));
    }

    /**
     * Libellé d'une variante dans les messages : référence produit + libellé de variante.
     */
    private function label(Variant $variant): string
    {
        return trim(($variant->getProduct()?->getReference() ?? '').' '.$variant->getLabel());
    }
}
