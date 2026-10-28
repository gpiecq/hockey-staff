<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\SaleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vente d'occasion immuable ; la date et le lieu sont ceux de l'opération `SALE_OUT`, l'auteur est `createdBy`.
 */
#[ORM\Entity(repositoryClass: SaleRepository::class)]
class Sale
{
    use TimestampableBlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    /** Acheteur en texte libre (nom, contact…). */
    #[ORM\Column(type: Types::TEXT)]
    private string $buyer;

    /** Joueur lié, facultatif. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Player $player;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    /** @var Collection<int, SaleLine> */
    #[ORM\OneToMany(targetEntity: SaleLine::class, mappedBy: 'sale', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** Opération de stock qui porte la vente (côté inverse ; renseigné par le constructeur de l'opération). */
    #[ORM\OneToOne(targetEntity: StockOperation::class, mappedBy: 'sale')]
    private StockOperation $operation;

    public function __construct(string $buyer, ?Player $player = null, ?string $comment = null)
    {
        $this->buyer = $buyer;
        $this->player = $player;
        $this->comment = $comment;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBuyer(): string
    {
        return $this->buyer;
    }

    public function getPlayer(): ?Player
    {
        return $this->player;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @internal réservé au StockMovementRecorder
     */
    public function addLine(SaleLine $line): void
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    /**
     * @return Collection<int, SaleLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /** Total TTC en centimes. */
    public function getTotalTtc(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->getPriceTtc();
        }

        return $total;
    }

    public function getOperation(): StockOperation
    {
        return $this->operation;
    }

    /**
     * @internal appelé par le constructeur de StockOperation
     */
    public function attachTo(StockOperation $operation): void
    {
        $this->operation = $operation;
    }
}
