<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\SupplierImportMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupplierImportMappingRepository::class)]
class SupplierImportMapping
{
    use TimestampableBlameableTrait;

    /** Champs cibles du mapping. */
    public const array FIELDS = [
        'reference', 'ean', 'designation', 'size', 'fit', 'side', 'width', 'color',
        'publicPriceTtc', 'clubPriceTtc', 'category1', 'category2', 'category3', 'brand',
    ];

    /** Champs cibles dont l'en-tête doit être présent dans le fichier. */
    public const array REQUIRED_FIELDS = ['reference', 'designation', 'category1', 'brand'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    /** @var array<string, string> champ cible => en-tête source */
    #[ORM\Column(name: 'source_columns', type: Types::JSON)]
    private array $columns = [];

    /** @var array<string, string> couleur fournisseur => couleur normalisée (ex. BLK => NOIR) */
    #[ORM\Column(type: Types::JSON)]
    private array $colorAliases = [];

    public function __construct(
        #[ORM\OneToOne(targetEntity: Supplier::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Supplier $supplier,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    /** @return array<string, string> */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** @param array<string, string> $columns */
    public function setColumns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /** @return array<string, string> */
    public function getColorAliases(): array
    {
        return $this->colorAliases;
    }

    /** @param array<string, string> $colorAliases */
    public function setColorAliases(array $colorAliases): static
    {
        $this->colorAliases = $colorAliases;

        return $this;
    }
}
