<?php

declare(strict_types=1);

namespace App\Entity;

use App\Catalogue\Money\VatCalculator;
use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\SeasonPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SeasonPriceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_season_price_variant_season', columns: ['variant_id', 'season_id'])]
#[UniqueEntity(fields: ['variant', 'season'], message: 'Un tarif existe déjà pour cette variante et cette saison.', errorPath: 'season')]
class SeasonPrice
{
    use TimestampableBlameableTrait;

    public const DEFAULT_VAT_RATE = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'La variante est obligatoire.')]
    private ?Variant $variant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'La saison est obligatoire.')]
    private ?Season $season = null;

    /** Prix club TTC en centimes. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le prix doit être positif.')]
    private ?int $clubPriceTtc = null;

    /** Prix public TTC en centimes. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le prix doit être positif.')]
    private ?int $publicPriceTtc = null;

    /** Taux de TVA en points de base (20 % = 2000). */
    #[ORM\Column(options: ['default' => self::DEFAULT_VAT_RATE])]
    #[Assert\PositiveOrZero(message: 'Le taux de TVA doit être positif.')]
    private int $vatRate = self::DEFAULT_VAT_RATE;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): ?Variant
    {
        return $this->variant;
    }

    public function setVariant(?Variant $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function getSeason(): ?Season
    {
        return $this->season;
    }

    public function setSeason(?Season $season): static
    {
        $this->season = $season;

        return $this;
    }

    public function getClubPriceTtc(): ?int
    {
        return $this->clubPriceTtc;
    }

    public function setClubPriceTtc(?int $clubPriceTtc): static
    {
        $this->clubPriceTtc = $clubPriceTtc;

        return $this;
    }

    public function getPublicPriceTtc(): ?int
    {
        return $this->publicPriceTtc;
    }

    public function setPublicPriceTtc(?int $publicPriceTtc): static
    {
        $this->publicPriceTtc = $publicPriceTtc;

        return $this;
    }

    public function getVatRate(): int
    {
        return $this->vatRate;
    }

    public function setVatRate(int $vatRate): static
    {
        $this->vatRate = $vatRate;

        return $this;
    }

    public function getClubPriceHt(): ?int
    {
        return null === $this->clubPriceTtc ? null : VatCalculator::htFromTtc($this->clubPriceTtc, $this->vatRate);
    }
}
