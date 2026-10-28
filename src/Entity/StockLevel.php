<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\ValuedQuantityTrait;
use App\Enum\StockCondition;
use App\Repository\StockLevelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quantité et valeur HT (centimes) d'une variante dans un lieu, pour un état (toujours Neuf pour un consommable).
 */
#[ORM\Entity(repositoryClass: StockLevelRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_stock_level_variant_location_condition', columns: ['variant_id', 'location_id', 'condition'])]
class StockLevel
{
    use ValuedQuantityTrait;

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

    #[ORM\Column(length: 20, enumType: StockCondition::class, options: ['default' => 'new'])]
    private StockCondition $condition;

    public function __construct(Variant $variant, Location $location, StockCondition $condition = StockCondition::New)
    {
        $this->variant = $variant;
        $this->location = $location;
        $this->condition = $condition;
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

    public function getCondition(): StockCondition
    {
        return $this->condition;
    }
}
