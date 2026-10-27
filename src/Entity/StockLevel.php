<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StockLevelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quantité et valeur HT (centimes) d'une variante en quantité dans un lieu.
 */
#[ORM\Entity(repositoryClass: StockLevelRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_stock_level_variant_location', columns: ['variant_id', 'location_id'])]
class StockLevel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Variant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Location $location;

    #[ORM\Column(options: ['default' => 0])]
    private int $quantity = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $valueHt = 0;

    public function __construct(Variant $variant, Location $location)
    {
        $this->variant = $variant;
        $this->location = $location;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): Variant
    {
        return $this->variant;
    }

    public function getLocation(): Location
    {
        return $this->location;
    }

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
