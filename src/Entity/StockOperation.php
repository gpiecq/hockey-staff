<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Enum\StockMovementType;
use App\Enum\StockOutReason;
use App\Repository\StockOperationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opération de stock immuable ; l'auteur est `createdBy`.
 */
#[ORM\Entity(repositoryClass: StockOperationRepository::class)]
class StockOperation
{
    use TimestampableBlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StockMovementType::class)]
    private StockMovementType $type;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Location $source;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Location $destination;

    #[ORM\Column(length: 20, nullable: true, enumType: StockOutReason::class)]
    private ?StockOutReason $outReason;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $documentReference;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** @var Collection<int, StockMovement> */
    #[ORM\OneToMany(targetEntity: StockMovement::class, mappedBy: 'operation', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $movements;

    public function __construct(
        StockMovementType $type,
        ?Location $source,
        ?Location $destination,
        ?StockOutReason $outReason = null,
        ?string $documentReference = null,
        ?string $comment = null,
    ) {
        $this->type = $type;
        $this->source = $source;
        $this->destination = $destination;
        $this->outReason = $outReason;
        $this->documentReference = $documentReference;
        $this->comment = $comment;
        $this->occurredAt = new \DateTimeImmutable();
        $this->movements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): StockMovementType
    {
        return $this->type;
    }

    public function getSource(): ?Location
    {
        return $this->source;
    }

    public function getDestination(): ?Location
    {
        return $this->destination;
    }

    public function getOutReason(): ?StockOutReason
    {
        return $this->outReason;
    }

    public function getDocumentReference(): ?string
    {
        return $this->documentReference;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function addMovement(StockMovement $movement): void
    {
        if (!$this->movements->contains($movement)) {
            $this->movements->add($movement);
        }
    }

    /**
     * @return Collection<int, StockMovement>
     */
    public function getMovements(): Collection
    {
        return $this->movements;
    }
}
