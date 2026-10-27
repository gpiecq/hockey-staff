<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\ImportAnomalyCode;

final readonly class NormalizedRow
{
    /**
     * @param list<string>        $categoryPath
     * @param list<ImportAnomaly> $anomalies
     */
    public function __construct(
        public int $line,
        public string $reference,
        public ?string $ean,
        public string $designation,
        public ?string $size,
        public ?string $fit,
        public ?string $side,
        public ?string $width,
        public ?string $color,
        public ?int $clubPriceTtc,
        public ?int $publicPriceTtc,
        public array $categoryPath,
        public string $brand,
        public array $anomalies,
    ) {
    }

    /** Même formule que Variant::getMatchKey() : size|fit|side|width|color. */
    public function matchKey(): string
    {
        return implode('|', [
            $this->size ?? '',
            $this->fit ?? '',
            $this->side ?? '',
            $this->width ?? '',
            $this->color ?? '',
        ]);
    }

    /**
     * Ligne rejetée : un champ obligatoire (référence, désignation, catégorie, marque) est vide.
     * Les champs correspondants valent alors '' (ou [] pour le chemin de catégorie) : l'analyse ne doit pas
     * traiter la ligne, seulement la reporter.
     */
    public function isRejected(): bool
    {
        foreach ($this->anomalies as $anomaly) {
            if (ImportAnomalyCode::MissingRequired === $anomaly->code || ImportAnomalyCode::ValueTooLong === $anomaly->code) {
                return true;
            }
        }

        return false;
    }
}
