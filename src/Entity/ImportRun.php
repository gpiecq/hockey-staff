<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableBlameableTrait;
use App\Enum\ImportRunStatus;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
class ImportRun
{
    use TimestampableBlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // @phpstan-ignore property.unusedType (assigné par Doctrine)
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ImportRunStatus::class)]
    private ImportRunStatus $status = ImportRunStatus::Pending;

    /** @var array<string, mixed>|null JSON de ImportDiff::toArray() */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $diff = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    /** @param string $storedPath chemin relatif au dossier var/imports/ */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Supplier $supplier,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Season $season,
        #[ORM\Column(length: 255)]
        private string $originalFilename,
        #[ORM\Column(length: 255)]
        private string $storedPath,
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

    public function getSeason(): Season
    {
        return $this->season;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getStoredPath(): string
    {
        return $this->storedPath;
    }

    public function getStatus(): ImportRunStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed>|null */
    public function getDiff(): ?array
    {
        return $this->diff;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    /** @param array<string, mixed> $diff */
    public function markAnalyzed(array $diff): static
    {
        $this->status = ImportRunStatus::Analyzed;
        $this->diff = $diff;
        $this->error = null;
        $this->analyzedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @param array<string, mixed> $appliedDiff diff effectivement appliqué (ré-analysé à l'application) */
    public function markApplied(array $appliedDiff): static
    {
        $this->status = ImportRunStatus::Applied;
        $this->diff = $appliedDiff;
        $this->appliedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markCancelled(): static
    {
        $this->status = ImportRunStatus::Cancelled;

        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->status = ImportRunStatus::Failed;
        $this->diff = null;
        $this->error = $error;

        return $this;
    }
}
