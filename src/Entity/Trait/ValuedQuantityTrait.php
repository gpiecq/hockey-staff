<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Quantité valorisée au coût unitaire moyen pondéré (CUMP) : valeur HT totale en centimes.
 * Partagé par les niveaux de stock et les détentions des joueurs.
 */
trait ValuedQuantityTrait
{
    #[ORM\Column(options: ['default' => 0])]
    private int $quantity = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $valueHt = 0;

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getValueHt(): int
    {
        return $this->valueHt;
    }

    public function averageCostHt(): ?int
    {
        if (0 === $this->quantity) {
            return null;
        }

        return (int) round($this->valueHt / $this->quantity);
    }

    /**
     * @internal réservé au StockMovementRecorder
     */
    public function add(int $quantity, int $valueHt): void
    {
        $this->quantity += $quantity;
        $this->valueHt += $valueHt;
    }

    /**
     * Retire des unités et renvoie la valeur retirée (coût moyen pondéré).
     *
     * @internal réservé au StockMovementRecorder
     */
    public function remove(int $quantity): int
    {
        if ($quantity > $this->quantity) {
            throw new \LogicException('Quantité insuffisante en stock.');
        }

        $removed = $quantity === $this->quantity
            ? $this->valueHt
            : (int) round($this->valueHt * $quantity / $this->quantity);

        $this->quantity -= $quantity;
        $this->valueHt -= $removed;

        return $removed;
    }
}
