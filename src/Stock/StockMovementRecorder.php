<?php

declare(strict_types=1);

namespace App\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Location;
use App\Entity\Player;
use App\Entity\PlayerHolding;
use App\Entity\Sale;
use App\Entity\SaleLine;
use App\Entity\StockLevel;
use App\Entity\StockMovement;
use App\Entity\StockOperation;
use App\Entity\Variant;
use App\Enum\ProductKind;
use App\Enum\StockCondition;
use App\Enum\StockMovementType;
use App\Enum\StockOutReason;
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

        $targets = [];
        foreach ($plans as $plan) {
            $targets[$this->levelKey($plan['line']->variant, $entry->location, $plan['condition'])] = [$plan['line']->variant, $entry->location, $plan['condition']];
        }

        return $this->em->wrapInTransaction(function () use ($entry, $plans, $targets, $season): StockOperation {
            $levels = $this->lockLevels($targets);
            $operation = new StockOperation($entry->type, null, $entry->location, null, $entry->documentReference, $entry->comment);
            $this->em->persist($operation);

            foreach ($plans as $plan) {
                $line = $plan['line'];
                $key = $this->levelKey($line->variant, $entry->location, $plan['condition']);
                $level = $levels[$key] ??= $this->newLevel($line->variant, $entry->location, $plan['condition']);
                $level->add($line->quantity, $line->quantity * $plan['cost']);
                $operation->addMovement(new StockMovement($operation, $line->variant, $line->quantity, $plan['cost'], $plan['catalogueValue'], $season, $plan['condition']));
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
            ['sources' => $sources] = $this->lockAndCheck($out->location, $out->lines);
            $operation = new StockOperation(StockMovementType::Out, $out->location, null, $out->reason, null, $out->comment);
            $this->em->persist($operation);

            foreach ($out->lines as $line) {
                $condition = $this->conditionOf($line->variant, $line->condition);
                $value = $sources[$this->levelKey($line->variant, $out->location, $condition)]->remove($line->quantity);
                $operation->addMovement(new StockMovement($operation, $line->variant, $line->quantity, (int) round($value / $line->quantity), null, $season, $condition));
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
            ['sources' => $sources, 'destinations' => $destinations] = $this->lockAndCheck($transfer->from, $transfer->lines, $transfer->to);
            $operation = new StockOperation(StockMovementType::Transfer, $transfer->from, $transfer->to, null, null, $transfer->comment);
            $this->em->persist($operation);

            // Le transfert conserve l'état et la valeur (CUMP du niveau de départ).
            foreach ($transfer->lines as $line) {
                $condition = $this->conditionOf($line->variant, $line->condition);
                $value = $sources[$this->levelKey($line->variant, $transfer->from, $condition)]->remove($line->quantity);
                $key = $this->levelKey($line->variant, $transfer->to, $condition);
                $destination = $destinations[$key] ??= $this->newLevel($line->variant, $transfer->to, $condition);
                $destination->add($line->quantity, $value);
                $operation->addMovement(new StockMovement($operation, $line->variant, $line->quantity, (int) round($value / $line->quantity), null, $season, $condition));
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
            $operation->addMovement(new StockMovement($operation, $loan->variant, $loan->quantity, (int) round($value / $loan->quantity), null, $season, $loan->condition));

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
            $operation->addMovement(new StockMovement($operation, $return->variant, $return->quantity, (int) round($value / $return->quantity), null, $season, $return->condition));
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
        // Consommable : état toujours Neuf, le choix éventuel est ignoré.
        if (ProductKind::Equipment === $gift->variant->getProduct()?->getKind()) {
            $condition = $gift->condition ?? throw new StockException('Choisissez l\'état.');
        } else {
            $condition = StockCondition::New;
        }
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($gift, $condition, $season): StockOperation {
            // Verrou et contrôle avant la première écriture.
            $level = $this->levels->findForUpdate($gift->variant, $gift->location, $condition);
            if (null === $level || $gift->quantity > $level->getQuantity()) {
                throw $this->shortStock($gift->variant, $condition, $gift->location, $level?->getQuantity() ?? 0);
            }
            $operation = new StockOperation(StockMovementType::GiftOut, $gift->location, null, null, null, $gift->comment, $gift->player, $gift->team);
            $this->em->persist($operation);
            $value = $level->remove($gift->quantity);
            $operation->addMovement(new StockMovement($operation, $gift->variant, $gift->quantity, (int) round($value / $gift->quantity), null, $season, $condition));

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
        // Contrôles de forme avant tout accès base ; clé de verrouillage => rang de la ligne saisie.
        $keys = [];
        foreach ($sale->lines as $index => $line) {
            if ($line->quantity < 1) {
                throw new StockException('La quantité doit être au moins 1.');
            }
            if (ProductKind::Equipment !== $line->variant->getProduct()?->getKind()) {
                throw new StockException(\sprintf('« %s » est un consommable : il ne se vend pas.', $this->label($line->variant)));
            }
            if (StockCondition::New === $line->condition) {
                throw new StockException(\sprintf('« %s » neuf : seule l\'occasion se vend.', $this->label($line->variant)));
            }
            $key = \sprintf('%012d-%s', (int) $line->variant->getId(), $line->condition->value);
            if (isset($keys[$key])) {
                throw new StockException(\sprintf('« %s » (%s) figure deux fois : augmentez la quantité.', $this->label($line->variant), $line->condition->label()));
            }
            $keys[$key] = $index;
        }
        // Ordre global de verrouillage (variante id, puis état ; un seul lieu) pour éviter les deadlocks ;
        // la vente garde l'ordre de saisie des lignes.
        ksort($keys);
        $lines = $sale->lines;
        $season = $this->seasons->get();

        return $this->em->wrapInTransaction(function () use ($sale, $buyer, $keys, $lines, $season): StockOperation {
            // Verrous, contrôles et prix avant la première écriture.
            $locked = [];
            foreach ($keys as $index) {
                $line = $lines[$index];
                $level = $this->levels->findForUpdate($line->variant, $sale->location, $line->condition);
                if (null === $level || $line->quantity > $level->getQuantity()) {
                    throw $this->shortStock($line->variant, $line->condition, $sale->location, $level?->getQuantity() ?? 0);
                }
                $locked[$index] = $level;
            }
            $plans = [];
            foreach ($lines as $index => $line) {
                $plans[] = [$line, $locked[$index], $this->pricing->price($line->variant, $line->discountRate, $line->finalPriceTtc)];
            }

            $record = new Sale($buyer, $sale->player, $sale->comment);
            $this->em->persist($record);
            $operation = new StockOperation(StockMovementType::SaleOut, $sale->location, null, null, null, null, $sale->player, null, $record);
            $this->em->persist($operation);

            foreach ($plans as [$line, $level, $price]) {
                $record->addLine(new SaleLine($record, $line->variant, $line->condition, $line->quantity, $price));
                $value = $level->remove($line->quantity);
                $operation->addMovement(new StockMovement($operation, $line->variant, $line->quantity, (int) round($value / $line->quantity), null, $season, $line->condition));
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

        foreach ($lines as $line) {
            if ($line->quantity < 1) {
                throw new StockException('La quantité doit être au moins 1.');
            }
        }
    }

    /**
     * Verrouille (PESSIMISTIC_WRITE) les niveaux de départ (et d'arrivée pour un transfert), puis contrôle la
     * disponibilité par (variante, état) ; à appeler dans la transaction. Les lignes d'une même variante dans
     * un même état sont cumulées.
     *
     * @param list<StockLine> $lines
     *
     * @return array{sources: array<string, StockLevel>, destinations: array<string, ?StockLevel>}
     */
    private function lockAndCheck(Location $from, array $lines, ?Location $to = null): array
    {
        $wanted = [];
        $targets = [];
        foreach ($lines as $line) {
            $condition = $this->conditionOf($line->variant, $line->condition);
            $key = $this->levelKey($line->variant, $from, $condition);
            $wanted[$key] = ($wanted[$key] ?? 0) + $line->quantity;
            $targets[$key] = [$line->variant, $from, $condition];
            if (null !== $to) {
                $targets[$this->levelKey($line->variant, $to, $condition)] = [$line->variant, $to, $condition];
            }
        }

        $locked = $this->lockLevels($targets);

        $sources = [];
        $destinations = [];
        foreach ($locked as $key => $level) {
            if (!isset($wanted[$key])) {
                $destinations[$key] = $level;
                continue;
            }
            [$variant, , $condition] = $targets[$key];
            if (null === $level || $wanted[$key] > $level->getQuantity()) {
                throw $this->shortStock($variant, $condition, $from, $level?->getQuantity() ?? 0);
            }
            $sources[$key] = $level;
        }

        return ['sources' => $sources, 'destinations' => $destinations];
    }

    /**
     * Verrouille les niveaux visés dans l'ordre global (variante id, lieu id, état) pour éviter les deadlocks
     * entre saisies concurrentes ; null pour un niveau qui n'existe pas encore.
     *
     * @param array<string, array{Variant, Location, StockCondition}> $targets clés issues de levelKey()
     *
     * @return array<string, ?StockLevel>
     */
    private function lockLevels(array $targets): array
    {
        ksort($targets);
        $levels = [];
        foreach ($targets as $key => [$variant, $location, $condition]) {
            $levels[$key] = $this->levels->findForUpdate($variant, $location, $condition);
        }

        return $levels;
    }

    private function levelKey(Variant $variant, Location $location, StockCondition $condition): string
    {
        return \sprintf('%012d-%012d-%s', (int) $variant->getId(), (int) $location->getId(), $condition->value);
    }

    /** Consommable : état toujours Neuf, le choix éventuel est ignoré. */
    private function conditionOf(Variant $variant, StockCondition $condition): StockCondition
    {
        return ProductKind::Equipment === $variant->getProduct()?->getKind() ? $condition : StockCondition::New;
    }

    /**
     * @return array{line: StockEntryLine, cost: int, catalogueValue: ?int, condition: StockCondition}
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

        return ['line' => $line, 'cost' => $cost, 'catalogueValue' => $isPurchase ? null : $clubPrice, 'condition' => $this->conditionOf($variant, $line->condition)];
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
