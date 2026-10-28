<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\RosterEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Joueur dans l'effectif d'une équipe pour une saison, avec son numéro. */
#[ORM\Entity(repositoryClass: RosterEntryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_roster_entry', columns: ['team_season_id', 'player_id'])]
class RosterEntry
{
    use TimestampableBlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TeamSeason::class, inversedBy: 'entries')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly TeamSeason $teamSeason,
        #[ORM\ManyToOne(targetEntity: Player::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly Player $player,
        #[ORM\Column(nullable: true)]
        #[Assert\Range(min: 0, max: 99, notInRangeMessage: 'Le numéro doit être compris entre {{ min }} et {{ max }}.')]
        private ?int $number = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeamSeason(): TeamSeason
    {
        return $this->teamSeason;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function setNumber(?int $number): static
    {
        $this->number = $number;

        return $this;
    }
}
