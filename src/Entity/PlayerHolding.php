<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\ValuedQuantityTrait;
use App\Repository\PlayerHoldingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Prêt en cours : quantité d'une variante détenue par un joueur, valorisée au coût (CUMP).
 * L'état n'est pas conservé (il est déclaré au retour) ; une détention à zéro est supprimée.
 */
#[ORM\Entity(repositoryClass: PlayerHoldingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_player_holding', columns: ['player_id', 'variant_id'])]
class PlayerHolding
{
    use ValuedQuantityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Player $player;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Variant $variant;

    public function __construct(Player $player, Variant $variant)
    {
        $this->player = $player;
        $this->variant = $variant;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getVariant(): Variant
    {
        return $this->variant;
    }
}
