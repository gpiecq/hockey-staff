<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Résultat (lecture seule) de la comparaison d'un fichier fournisseur avec le catalogue.
 * Sérialisé en JSON dans ImportRun::$diff ; Task 10 l'applique sans relire le fichier.
 *
 * Formes des entrées (les prix sont en centimes TTC, null = absent) :
 *
 * - newProducts : un par référence inconnue du fournisseur, champs issus de la première ligne acceptée
 *   {line, reference, designation, brand (nom normalisé), categoryPath (list<string>, 1 à 3 niveaux)}
 * - newVariants : une par ligne acceptée sans variante correspondante ; productId = produit existant,
 *   null si le produit est dans newProducts (même reference)
 *   {line, reference, productId, ean, size, fit, side, width, color, clubPriceTtc, publicPriceTtc,
 *    designation, brand, categoryPath}
 * - changedVariants : variante rapprochée dont au moins un champ change ; les champs du produit
 *   (designation, brand, category) sont répétés sur CHAQUE variante rapprochée du produit (R3) ;
 *   brand = nom normalisé, category = chemin « A › B › C » ; autres clés : size, fit, side, width, color,
 *   ean (gencode renseigné pour une variante qui n'en avait pas)
 *   {variantId, line, changes: array<clé, {from: ?string, to: ?string}>}
 * - priceChanges : une entrée si au moins un des deux prix change (un prix nul du fichier n'efface jamais
 *   le prix stocké : from = to = stocké) ; variantId null pour une nouvelle variante (retrouvée par ligne)
 *   {variantId, line, club: {from, to}, public: {from, to}}
 * - deactivatedVariantIds / reactivatedVariantIds : identifiants de variantes
 * - rejectedLines : numéros de ligne Excel ignorés par l'application
 * - anomalies : {line, code (ImportAnomalyCode::value), message}
 *
 * Les anomalies seules ne sont pas des changements : hasChanges() les ignore.
 *
 * @phpstan-type NewProduct array{line: int, reference: string, designation: string, brand: string, categoryPath: list<string>}
 * @phpstan-type NewVariant array{line: int, reference: string, productId: int|null, ean: string|null, size: string|null, fit: string|null, side: string|null, width: string|null, color: string|null, clubPriceTtc: int|null, publicPriceTtc: int|null, designation: string, brand: string, categoryPath: list<string>}
 * @phpstan-type Change array{from: string|null, to: string|null}
 * @phpstan-type ChangedVariant array{variantId: int, line: int, changes: array<string, Change>}
 * @phpstan-type Price array{from: int|null, to: int|null}
 * @phpstan-type PriceChange array{variantId: int|null, line: int, club: Price, public: Price}
 * @phpstan-type Anomaly array{line: int, code: string, message: string}
 */
final readonly class ImportDiff
{
    /**
     * @param list<NewProduct>     $newProducts
     * @param list<NewVariant>     $newVariants
     * @param list<ChangedVariant> $changedVariants
     * @param list<PriceChange>    $priceChanges
     * @param list<int>            $deactivatedVariantIds
     * @param list<int>            $reactivatedVariantIds
     * @param list<int>            $rejectedLines
     * @param list<Anomaly>        $anomalies
     */
    public function __construct(
        public array $newProducts = [],
        public array $newVariants = [],
        public array $changedVariants = [],
        public array $priceChanges = [],
        public array $deactivatedVariantIds = [],
        public array $reactivatedVariantIds = [],
        public array $rejectedLines = [],
        public array $anomalies = [],
    ) {
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return [
            'newProducts' => \count($this->newProducts),
            'newVariants' => \count($this->newVariants),
            'changedVariants' => \count($this->changedVariants),
            'priceChanges' => \count($this->priceChanges),
            'deactivated' => \count($this->deactivatedVariantIds),
            'reactivated' => \count($this->reactivatedVariantIds),
            'rejected' => \count($this->rejectedLines),
            'anomalies' => \count($this->anomalies),
        ];
    }

    /** Vrai s'il y a quelque chose à appliquer ; les anomalies et lignes rejetées seules ne comptent pas. */
    public function hasChanges(): bool
    {
        return [] !== $this->newProducts
            || [] !== $this->newVariants
            || [] !== $this->changedVariants
            || [] !== $this->priceChanges
            || [] !== $this->deactivatedVariantIds
            || [] !== $this->reactivatedVariantIds;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'newProducts' => $this->newProducts,
            'newVariants' => $this->newVariants,
            'changedVariants' => $this->changedVariants,
            'priceChanges' => $this->priceChanges,
            'deactivatedVariantIds' => $this->deactivatedVariantIds,
            'reactivatedVariantIds' => $this->reactivatedVariantIds,
            'rejectedLines' => $this->rejectedLines,
            'anomalies' => $this->anomalies,
        ];
    }

    /**
     * @param array<string, mixed> $data tableau issu de toArray(), éventuellement relu depuis le JSON de ImportRun
     */
    public static function fromArray(array $data): self
    {
        // Contenu écrit par toArray() : les formes sont garanties par l'écriture, pas revalidées.
        /** @var array{newProducts?: list<NewProduct>, newVariants?: list<NewVariant>, changedVariants?: list<ChangedVariant>, priceChanges?: list<PriceChange>, deactivatedVariantIds?: list<int>, reactivatedVariantIds?: list<int>, rejectedLines?: list<int>, anomalies?: list<Anomaly>} $typed */
        $typed = $data;

        return new self(
            $typed['newProducts'] ?? [],
            $typed['newVariants'] ?? [],
            $typed['changedVariants'] ?? [],
            $typed['priceChanges'] ?? [],
            $typed['deactivatedVariantIds'] ?? [],
            $typed['reactivatedVariantIds'] ?? [],
            $typed['rejectedLines'] ?? [],
            $typed['anomalies'] ?? [],
        );
    }
}
