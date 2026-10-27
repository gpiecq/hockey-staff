<?php

declare(strict_types=1);

namespace App\Entity;

use App\Catalogue\NameNormalizer;
use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\BrandRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_brand_name', columns: ['name'], options: ['where' => '(deleted_at IS NULL)'])]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt')]
#[UniqueEntity(fields: ['name'], message: 'Ce nom est déjà utilisé.')]
class Brand
{
    use TimestampableBlameableTrait;
    public const int NAME_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: self::NAME_MAX_LENGTH, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.')]
    private string $name = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    // @phpstan-ignore property.unusedType (assigné par Gedmo SoftDeleteable)
    private ?\DateTimeImmutable $deletedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = NameNormalizer::normalize($name);

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
