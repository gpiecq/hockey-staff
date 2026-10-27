<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Enum\TrackingMode;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_product_supplier_reference', columns: ['supplier_id', 'reference'], options: ['where' => '(deleted_at IS NULL)'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt')]
#[UniqueEntity(fields: ['supplier', 'reference'], message: 'Cette référence existe déjà chez ce fournisseur.', errorPath: 'reference')]
class Product
{
    use TimestampableBlameableTrait;
    public const int REFERENCE_MAX_LENGTH = 100;
    public const int DESIGNATION_MAX_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Le fournisseur est obligatoire.')]
    private ?Supplier $supplier = null;

    #[ORM\Column(length: self::REFERENCE_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'La référence est obligatoire.')]
    #[Assert\Length(max: self::REFERENCE_MAX_LENGTH, maxMessage: 'La référence ne peut pas dépasser {{ limit }} caractères.')]
    private string $reference = '';

    #[ORM\Column(length: self::DESIGNATION_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'La désignation est obligatoire.')]
    #[Assert\Length(max: self::DESIGNATION_MAX_LENGTH, maxMessage: 'La désignation ne peut pas dépasser {{ limit }} caractères.')]
    private string $designation = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Brand $brand = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'La catégorie est obligatoire.')]
    private ?Category $category = null;

    #[ORM\Column(length: 20, enumType: TrackingMode::class)]
    private TrackingMode $trackingMode = TrackingMode::Quantity;

    /** @var Collection<int, Variant> */
    #[ORM\OneToMany(targetEntity: Variant::class, mappedBy: 'product')]
    private Collection $variants;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    // @phpstan-ignore property.unusedType (assigné par Gedmo SoftDeleteable)
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->variants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): static
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = trim($reference);

        return $this;
    }

    public function getDesignation(): string
    {
        return $this->designation;
    }

    public function setDesignation(string $designation): static
    {
        $this->designation = trim($designation);

        return $this;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function setBrand(?Brand $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getTrackingMode(): TrackingMode
    {
        return $this->trackingMode;
    }

    public function setTrackingMode(TrackingMode $trackingMode): static
    {
        $this->trackingMode = $trackingMode;

        return $this;
    }

    /**
     * @return Collection<int, Variant>
     */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(Variant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
        }
        $variant->setProduct($this);

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
