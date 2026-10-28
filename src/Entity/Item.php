<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Enum\ItemStatus;
use App\Enum\StockCondition;
use App\Repository\ItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pièce suivie individuellement (code HS-NNNNNN).
 */
#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_item_code', columns: ['code'])]
class Item
{
    use TimestampableBlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Variant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Location $location;

    /** Coût d'achat HT en centimes. */
    #[ORM\Column]
    private int $purchaseCostHt;

    #[ORM\Column(length: 20, enumType: StockCondition::class)]
    private StockCondition $condition;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serialNumber;

    #[ORM\Column(length: 20, enumType: ItemStatus::class)]
    private ItemStatus $status = ItemStatus::InStock;

    /** Joueur détenteur, renseigné tant que la pièce est prêtée. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    // @phpstan-ignore property.unusedType (plus de prêt pièce par pièce : lu jusqu'à la suppression des pièces)
    private ?Player $holder = null;

    public function __construct(
        string $code,
        Variant $variant,
        Location $location,
        int $purchaseCostHt,
        StockCondition $condition = StockCondition::New,
        ?string $serialNumber = null,
    ) {
        $this->code = $code;
        $this->variant = $variant;
        $this->location = $location;
        $this->purchaseCostHt = $purchaseCostHt;
        $this->condition = $condition;
        $this->serialNumber = $serialNumber;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getVariant(): Variant
    {
        return $this->variant;
    }

    public function getLocation(): Location
    {
        return $this->location;
    }

    public function getPurchaseCostHt(): int
    {
        return $this->purchaseCostHt;
    }

    public function getCondition(): StockCondition
    {
        return $this->condition;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function getStatus(): ItemStatus
    {
        return $this->status;
    }

    public function getHolder(): ?Player
    {
        return $this->holder;
    }

    /**
     * @internal réservé au StockMovementRecorder
     */
    public function moveTo(Location $location): void
    {
        $this->location = $location;
    }

    /**
     * @internal réservé au StockMovementRecorder ; le lieu est conservé
     */
    public function remove(): void
    {
        $this->status = ItemStatus::Removed;
    }

    /**
     * @internal réservé au StockMovementRecorder ; le lieu est conservé
     */
    public function gift(): void
    {
        $this->status = ItemStatus::Gifted;
    }

    /**
     * @internal réservé au StockMovementRecorder ; le lieu est conservé
     */
    public function sell(): void
    {
        $this->status = ItemStatus::Sold;
    }
}
