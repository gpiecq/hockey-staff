<?php

declare(strict_types=1);

namespace App\Entity;

use App\Catalogue\NameNormalizer;
use App\Entity\Trait\TimestampableBlameableTrait;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Arbre de catégories sur 3 niveaux au maximum (ex. EQUIPEMENT › JOUEUR › PATIN).
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_category_parent_name', columns: ['parent_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uniq_category_root_name', columns: ['name'], options: ['where' => '(parent_id IS NULL)'])]
#[UniqueEntity(fields: ['parent', 'name'], message: 'Ce nom est déjà utilisé à ce niveau.', errorPath: 'name', ignoreNull: false)]
class Category
{
    use TimestampableBlameableTrait;

    public const MAX_DEPTH = 3;
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

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        for ($ancestor = $this->parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if ($ancestor === $this) {
                $context->buildViolation('Une catégorie ne peut pas être sa propre sous-catégorie.')
                    ->atPath('parent')
                    ->addViolation();

                return;
            }
        }

        if ($this->getDepth() + $this->getHeight() - 1 > self::MAX_DEPTH) {
            $context->buildViolation('Une catégorie ne peut pas dépasser '.self::MAX_DEPTH.' niveaux.')
                ->atPath('parent')
                ->addViolation();
        }
    }

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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /** Niveau de la catégorie : 1 pour une racine. */
    public function getDepth(): int
    {
        $depth = 1;
        // borne de sécurité : un cycle (refusé par la validation) ne doit pas boucler
        for ($node = $this->parent; null !== $node && $depth <= 2 * self::MAX_DEPTH; $node = $node->parent) {
            ++$depth;
        }

        return $depth;
    }

    public function getRoot(): self
    {
        $root = $this;
        for ($i = 0; null !== $root->parent && $i < 2 * self::MAX_DEPTH; ++$i) {
            $root = $root->parent;
        }

        return $root;
    }

    /** Chemin complet, ex. « EQUIPEMENT › JOUEUR › PATIN ». */
    public function getPath(): string
    {
        $names = [$this->name];
        for ($node = $this->parent; null !== $node && \count($names) <= 2 * self::MAX_DEPTH; $node = $node->parent) {
            array_unshift($names, $node->name);
        }

        return implode(' › ', $names);
    }

    /** Hauteur du sous-arbre (1 pour une feuille). */
    private function getHeight(): int
    {
        $height = 0;
        foreach ($this->children as $child) {
            $height = max($height, $child->getHeight());
        }

        return $height + 1;
    }
}
