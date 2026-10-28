<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\TeamSeasonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Effectif d'une équipe pour une saison. */
#[ORM\Entity(repositoryClass: TeamSeasonRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_team_season', columns: ['team_id', 'season_id'])]
class TeamSeason
{
    use TimestampableBlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    /** @var Collection<int, RosterEntry> */
    #[ORM\OneToMany(targetEntity: RosterEntry::class, mappedBy: 'teamSeason')]
    private Collection $entries;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Team::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly Team $team,
        #[ORM\ManyToOne(targetEntity: Season::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly Season $season,
    ) {
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getSeason(): Season
    {
        return $this->season;
    }

    /** @return Collection<int, RosterEntry> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }
}
