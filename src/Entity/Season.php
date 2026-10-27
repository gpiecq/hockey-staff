<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\SeasonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: SeasonRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_season_label', columns: ['label'])]
#[ORM\UniqueConstraint(name: 'uniq_season_current', columns: ['is_current'], options: ['where' => 'is_current'])]
#[UniqueEntity(fields: ['label'], message: 'Cette saison existe déjà.')]
class Season
{
    use TimestampableBlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\Column(length: 9)]
    private string $label;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCurrent = false;

    public function __construct(string $label, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn)
    {
        $this->label = $label;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $consecutive = 1 === preg_match('/^(\d{4})-(\d{4})$/', $this->label, $m) && (int) $m[2] === (int) $m[1] + 1;
        if (!$consecutive) {
            $context->buildViolation('Le libellé doit être de la forme AAAA-AAAA avec deux années consécutives (ex. 2026-2027).')
                ->atPath('label')
                ->addViolation();
        }

        if ($this->startsOn >= $this->endsOn) {
            $context->buildViolation('La date de début doit être antérieure à la date de fin.')
                ->atPath('endsOn')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(\DateTimeImmutable $startsOn): static
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function setEndsOn(\DateTimeImmutable $endsOn): static
    {
        $this->endsOn = $endsOn;

        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function setCurrent(bool $isCurrent): static
    {
        $this->isCurrent = $isCurrent;

        return $this;
    }
}
