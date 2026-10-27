<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\VariantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VariantRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_variant_ean', columns: ['ean'], options: ['where' => '(deleted_at IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_variant_product_match_key', columns: ['product_id', 'match_key'], options: ['where' => '(deleted_at IS NULL)'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt')]
#[UniqueEntity(fields: ['ean'], message: 'Ce code EAN est déjà utilisé.', errorPath: 'ean')]
#[UniqueEntity(fields: ['product', 'matchKey'], message: 'Cette variante existe déjà pour ce produit.', errorPath: 'size')]
class Variant
{
    use TimestampableBlameableTrait;
    /** Longueur max des colonnes taille, coupe, côté, largeur et couleur. */
    public const int ATTRIBUTE_MAX_LENGTH = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Le produit est obligatoire.')]
    private ?Product $product = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20, maxMessage: 'L\'EAN ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $ean = null;

    #[ORM\Column(length: self::ATTRIBUTE_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::ATTRIBUTE_MAX_LENGTH, maxMessage: 'Cette valeur ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $size = null;

    #[ORM\Column(length: self::ATTRIBUTE_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::ATTRIBUTE_MAX_LENGTH, maxMessage: 'Cette valeur ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $fit = null;

    #[ORM\Column(length: self::ATTRIBUTE_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::ATTRIBUTE_MAX_LENGTH, maxMessage: 'Cette valeur ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $side = null;

    #[ORM\Column(length: self::ATTRIBUTE_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::ATTRIBUTE_MAX_LENGTH, maxMessage: 'Cette valeur ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $width = null;

    #[ORM\Column(length: self::ATTRIBUTE_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::ATTRIBUTE_MAX_LENGTH, maxMessage: 'Cette valeur ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $color = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $extraAttributes = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(length: 260)]
    private string $matchKey = '||||';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    // @phpstan-ignore property.unusedType (assigné par Gedmo SoftDeleteable)
    private ?\DateTimeImmutable $deletedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): static
    {
        $this->ean = $this->clean($ean);

        return $this;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): static
    {
        $this->size = $this->clean($size);
        $this->refreshMatchKey();

        return $this;
    }

    public function getFit(): ?string
    {
        return $this->fit;
    }

    public function setFit(?string $fit): static
    {
        $this->fit = $this->clean($fit);
        $this->refreshMatchKey();

        return $this;
    }

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function setSide(?string $side): static
    {
        $this->side = $this->clean($side);
        $this->refreshMatchKey();

        return $this;
    }

    public function getWidth(): ?string
    {
        return $this->width;
    }

    public function setWidth(?string $width): static
    {
        $this->width = $this->clean($width);
        $this->refreshMatchKey();

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $this->clean($color);
        $this->refreshMatchKey();

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getExtraAttributes(): array
    {
        return $this->extraAttributes;
    }

    /**
     * @param array<string, string> $extraAttributes
     */
    public function setExtraAttributes(array $extraAttributes): static
    {
        $this->extraAttributes = $extraAttributes;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** Clé de rapprochement : size|fit|side|width|color (« » pour les valeurs absentes). */
    public function getMatchKey(): string
    {
        return $this->matchKey;
    }

    /** Attributs non vides joints par « · », ex. « SR L · FIT2 · NOIR ». */
    public function getLabel(): string
    {
        return implode(' · ', array_filter(
            [$this->size, $this->fit, $this->side, $this->width, $this->color],
            static fn (?string $value): bool => null !== $value && '' !== $value,
        ));
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    private function clean(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    private function refreshMatchKey(): void
    {
        $this->matchKey = implode('|', [
            $this->size ?? '',
            $this->fit ?? '',
            $this->side ?? '',
            $this->width ?? '',
            $this->color ?? '',
        ]);
    }
}
