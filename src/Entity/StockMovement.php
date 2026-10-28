<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StockCondition;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne immuable d'une opération de stock. Montants en centimes HT.
 */
#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
class StockMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'movements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private StockOperation $operation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Variant $variant;

    #[ORM\Column]
    private int $quantity;

    /** Coût unitaire HT en centimes. */
    #[ORM\Column]
    private int $unitCostHt;

    /** Valeur catalogue HT en centimes (dotation). */
    #[ORM\Column(nullable: true)]
    private ?int $catalogueValueHt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Season $season;

    /** État des unités déplacées (toujours Neuf pour un consommable). */
    #[ORM\Column(length: 20, enumType: StockCondition::class, options: ['default' => 'new'])]
    private StockCondition $condition;

    public function __construct(
        StockOperation $operation,
        Variant $variant,
        int $quantity,
        int $unitCostHt,
        ?int $catalogueValueHt = null,
        ?Season $season = null,
        StockCondition $condition = StockCondition::New,
    ) {
        $this->operation = $operation;
        $this->variant = $variant;
        $this->quantity = $quantity;
        $this->unitCostHt = $unitCostHt;
        $this->catalogueValueHt = $catalogueValueHt;
        $this->season = $season;
        $this->condition = $condition;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperation(): StockOperation
    {
        return $this->operation;
    }

    public function getVariant(): Variant
    {
        return $this->variant;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitCostHt(): int
    {
        return $this->unitCostHt;
    }

    public function getCatalogueValueHt(): ?int
    {
        return $this->catalogueValueHt;
    }

    public function getSeason(): ?Season
    {
        return $this->season;
    }

    public function getCondition(): StockCondition
    {
        return $this->condition;
    }
}
