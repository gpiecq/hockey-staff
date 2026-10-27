<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalogue\Ean;
use App\Entity\Category;
use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\Variant;
use App\Enum\ImportAnomalyCode;
use App\Import\Reader\SpreadsheetReader;
use App\Repository\SupplierImportMappingRepository;
use App\Stock\StockPresence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Compare un fichier fournisseur normalisé au catalogue et produit un diff. Lecture seule : rien n'est
 * persisté (ni produit, ni marque, ni catégorie).
 *
 * Rapprochement, ligne par ligne, dans l'ordre du fichier :
 *  1. avec gencode : variante vivante du fournisseur portant ce gencode ; sa référence doit être celle de la ligne
 *     (sinon EanChanged). Sinon, gencode d'un autre fournisseur : EanOtherSupplier. Sinon, repli sur
 *     référence + attributs : si cette variante a un autre gencode, EanChanged.
 *  2. sans gencode : référence + attributs (matchKey).
 * Une ligne rejetée qui identifie une variante existante la compte comme présente (elle n'est pas désactivée).
 */
final class CatalogueImportAnalyzer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SpreadsheetReader $reader,
        private readonly RowNormalizer $normalizer,
        private readonly SupplierImportMappingRepository $mappings,
        private readonly StockPresence $stockPresence,
        /** Dossier racine de ImportRun::getStoredPath(). */
        #[Autowire('%app.imports_dir%')]
        private readonly string $importsDir,
    ) {
    }

    /**
     * @throws \DomainException                  mapping absent ou chemin invalide
     * @throws Exception\MissingColumnsException en-têtes obligatoires absents
     */
    public function analyze(ImportRun $run): ImportDiff
    {
        $supplier = $run->getSupplier();
        $mapping = $this->mappings->findOneBy(['supplier' => $supplier])
            ?? throw new \DomainException('Aucun mapping d\'import n\'est configuré pour ce fournisseur.');

        // Précharge les catégories (une requête) : sert à résoudre les chemins du fichier et évite toute requête
        // lors de Category::getPath().
        $knownPaths = [];
        foreach ($this->em->getRepository(Category::class)->findAll() as $category) {
            $knownPaths[$category->getPath()] = true;
        }

        /** @var list<string> $brandNames */
        $brandNames = $this->em->createQuery('SELECT b.name FROM App\Entity\Brand b')->getSingleColumnResult();
        $knownBrands = array_fill_keys($brandNames, true);

        $rows = [];
        foreach ($this->reader->read($this->resolvePath($run), $mapping) as $line => $raw) {
            $row = $this->normalizer->normalize($line, $raw, $mapping);
            // Une catégorie introuvable n'est jamais créée : ligne traitée avec un chemin vide, simple avertissement.
            if ([] !== $row->categoryPath && !isset($knownPaths[implode(' › ', $row->categoryPath)])) {
                $row = new NormalizedRow(
                    $row->line, $row->reference, $row->ean, $row->designation, $row->size, $row->fit, $row->side,
                    $row->width, $row->color, $row->clubPriceTtc, $row->publicPriceTtc, [], $row->brand,
                    [...$row->anomalies, new ImportAnomaly($row->line, ImportAnomalyCode::CategoryUnknown, \sprintf(
                        'Catégorie « %s » inconnue : produit importé sans catégorie.', implode(' › ', $row->categoryPath),
                    ))],
                );
            }
            $rows[] = $row;
        }

        /** @var list<Product> $products */
        $products = $this->em->createQueryBuilder()
            ->select('p', 'b', 'c')->from(Product::class, 'p')
            ->leftJoin('p.brand', 'b')->leftJoin('p.category', 'c')
            ->where('p.supplier = :supplier')->setParameter('supplier', $supplier)
            ->getQuery()->getResult();
        $productByReference = [];
        foreach ($products as $product) {
            $productByReference[$product->getReference()] = $product;
        }

        /** @var list<Variant> $variants */
        $variants = $this->em->createQueryBuilder()
            ->select('v', 'p')->from(Variant::class, 'v')
            ->join('v.product', 'p')
            ->where('p.supplier = :supplier')->setParameter('supplier', $supplier)
            ->getQuery()->getResult();
        $variantByEan = [];
        $variantByKey = [];
        foreach ($variants as $variant) {
            if (null !== $variant->getEan()) {
                $variantByEan[Ean::key($variant->getEan())] = $variant;
            }
            $variantByKey[$variant->getProduct()?->getReference().'|'.$variant->getMatchKey()] = $variant;
        }

        /** @var array<int, array{club: int|null, public: int|null}> $storedPrices */
        $storedPrices = [];
        /** @var list<array{variantId: int, club: int|null, public: int|null}> $priceRows */
        $priceRows = $this->em->createQuery(
            'SELECT IDENTITY(sp.variant) AS variantId, sp.clubPriceTtc AS club, sp.publicPriceTtc AS public
             FROM App\Entity\SeasonPrice sp JOIN sp.variant v JOIN v.product p
             WHERE sp.season = :season AND p.supplier = :supplier',
        )->setParameter('season', $run->getSeason())->setParameter('supplier', $supplier)->getArrayResult();
        foreach ($priceRows as $priceRow) {
            $storedPrices[(int) $priceRow['variantId']] = ['club' => $priceRow['club'], 'public' => $priceRow['public']];
        }

        /** @var list<array{ean: string}> $otherRows */
        $otherRows = $this->em->createQuery(
            'SELECT v.ean AS ean FROM App\Entity\Variant v JOIN v.product p
             WHERE p.supplier != :supplier AND v.ean IS NOT NULL',
        )->setParameter('supplier', $supplier)->getArrayResult();
        $otherSupplierEans = array_fill_keys(array_map(Ean::key(...), array_column($otherRows, 'ean')), true);

        // Les index uniques sont partiels (deleted_at IS NULL) : seules les variantes vivantes d'un produit
        // supprimé (invisibles via le filtre softdeleteable) peuvent encore détenir un EAN. Requête DBAL : pas de filtre.
        $deletedHolders = [];
        $holderRows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT v.ean AS ean, p.reference AS reference FROM variant v JOIN product p ON p.id = v.product_id
             WHERE v.deleted_at IS NULL AND p.deleted_at IS NOT NULL AND v.ean IS NOT NULL',
        );
        foreach ($holderRows as $holderRow) {
            \assert(\is_string($holderRow['ean']) && \is_string($holderRow['reference']));
            $deletedHolders[Ean::key($holderRow['ean'])] = $holderRow['reference'];
        }

        $builder = new ImportDiffBuilder();
        $present = [];
        $claimed = [];
        $seenEans = [];
        $newKeys = [];
        /** @var array<string, array{variantId: int, line: int}> $movedKeys */
        $movedKeys = [];
        $newReferences = [];
        /** @var array<string, true> $lockChecked */
        $lockChecked = [];
        /** @var array<string, NormalizedRow> $firstByReference */
        $firstByReference = [];

        foreach ($rows as $row) {
            foreach ($row->anomalies as $anomaly) {
                $builder->anomaly($anomaly->line, $anomaly->code, $anomaly->message);
            }
            if ($row->isRejected()) {
                $builder->reject($row->line);
                $identified = (null !== $row->ean ? ($variantByEan[Ean::key($row->ean)] ?? null) : null)
                    ?? ('' !== $row->reference ? ($variantByKey[$row->reference.'|'.$row->matchKey()] ?? null) : null);
                if (null !== $identified) {
                    $present[(int) $identified->getId()] = true;
                }
                continue;
            }

            $ean = $row->ean;
            $eanKey = null !== $ean ? Ean::key($ean) : '';
            $key = $row->reference.'|'.$row->matchKey();
            $byKey = $variantByKey[$key] ?? null;

            if (null !== $ean && isset($seenEans[$eanKey])) {
                $builder->rejectWith($row->line, ImportAnomalyCode::EanDuplicateInFile, \sprintf(
                    'Gencode « %s » déjà présent à la ligne %d : ligne ignorée.', $ean, $seenEans[$eanKey],
                ));
                continue;
            }
            if (null !== $ean) {
                $seenEans[$eanKey] = $row->line;
            }

            $matched = null;
            if (null === $ean) {
                $matched = $byKey;
            } elseif (null !== ($byEan = $variantByEan[$eanKey] ?? null)) {
                $existingReference = $byEan->getProduct()?->getReference();
                if ($existingReference !== $row->reference) {
                    $present[(int) $byEan->getId()] = true;
                    $builder->rejectWith($row->line, ImportAnomalyCode::EanChanged, \sprintf(
                        'Le gencode « %s » appartient à la référence « %s » du catalogue, mais la ligne porte la référence « %s » : ligne ignorée.',
                        $ean, $existingReference, $row->reference,
                    ));
                    continue;
                }
                $matched = $byEan;
            } elseif (isset($otherSupplierEans[$eanKey])) {
                if (null !== $byKey) {
                    $present[(int) $byKey->getId()] = true;
                }
                $builder->rejectWith($row->line, ImportAnomalyCode::EanOtherSupplier, \sprintf(
                    'Le gencode « %s » est déjà rattaché à un autre fournisseur : ligne ignorée.', $ean,
                ));
                continue;
            } elseif (isset($deletedHolders[$eanKey])) {
                if (null !== $byKey) {
                    $present[(int) $byKey->getId()] = true;
                }
                $builder->rejectWith($row->line, ImportAnomalyCode::EanHeldByDeletedProduct, \sprintf(
                    'Le gencode « %s » est encore porté par une variante du produit supprimé « %s » : ligne ignorée (restaurer ou nettoyer ce produit).',
                    $ean, $deletedHolders[$eanKey],
                ));
                continue;
            } elseif (null !== $byKey) {
                if (null !== $byKey->getEan()) {
                    $present[(int) $byKey->getId()] = true;
                    $builder->rejectWith($row->line, ImportAnomalyCode::EanChanged, \sprintf(
                        'La référence « %s » avec ces attributs existe avec le gencode « %s » ; le fichier indique « %s » : conflit, ligne ignorée.',
                        $row->reference, $byKey->getEan(), $ean,
                    ));
                    continue;
                }
                $matched = $byKey;
            }

            if (null !== $matched) {
                if (null !== $byKey && $byKey !== $matched) {
                    $present[(int) $matched->getId()] = true;
                    $builder->rejectWith($row->line, ImportAnomalyCode::VariantKeyCollision, \sprintf(
                        'Les nouveaux attributs de la variante de gencode « %s » (référence « %s ») sont ceux d\'une autre variante du même produit : ligne ignorée.',
                        $ean, $row->reference,
                    ));
                    continue;
                }
                if (isset($claimed[(int) $matched->getId()])) {
                    $builder->rejectWith($row->line, ImportAnomalyCode::VariantDuplicateInFile, \sprintf(
                        'La variante (référence « %s », attributs identiques) est déjà présente à la ligne %d : ligne ignorée.',
                        $row->reference, $claimed[(int) $matched->getId()],
                    ));
                    continue;
                }
                if (null === $byKey && isset($newKeys[$key])) {
                    // R24 : la variante déplace sa clé sur celle d'une nouvelle variante créée par ce même fichier.
                    $present[(int) $matched->getId()] = true;
                    $builder->rejectWith($row->line, ImportAnomalyCode::VariantKeyCollision, \sprintf(
                        'Les nouveaux attributs de la variante de gencode « %s » (référence « %s ») sont ceux d\'une variante créée à la ligne %d : ligne ignorée.',
                        $ean, $row->reference, $newKeys[$key],
                    ));
                    continue;
                }
                if (null === $byKey && isset($movedKeys[$key])) {
                    $present[(int) $matched->getId()] = true;
                    $builder->rejectWith($row->line, ImportAnomalyCode::VariantKeyCollision, \sprintf(
                        'Les nouveaux attributs de la variante de gencode « %s » (référence « %s ») sont ceux d\'une autre variante modifiée à la ligne %d : ligne ignorée.',
                        $ean, $row->reference, $movedKeys[$key]['line'],
                    ));
                    continue;
                }
            } elseif (isset($movedKeys[$key])) {
                // R24, ordre inverse : la ligne qui déplaçait sa clé jusqu'ici est rejetée et ses changements retirés.
                $moved = $movedKeys[$key];
                unset($movedKeys[$key]);
                $builder->retract($moved['variantId']);
                $builder->rejectWith($moved['line'], ImportAnomalyCode::VariantKeyCollision, \sprintf(
                    'Les nouveaux attributs de la variante (référence « %s ») sont ceux d\'une variante créée à la ligne %d : ligne %d ignorée.',
                    $row->reference, $row->line, $moved['line'],
                ));
            }
            if (null === $matched && isset($newKeys[$key])) {
                $builder->rejectWith($row->line, ImportAnomalyCode::VariantDuplicateInFile, \sprintf(
                    'La variante (référence « %s », attributs identiques) est déjà présente à la ligne %d : ligne ignorée.',
                    $row->reference, $newKeys[$key],
                ));
                continue;
            }

            // Ligne acceptée. Les champs du produit viennent de la première ligne acceptée de la référence.
            $first = $firstByReference[$row->reference] ??= $row;
            if ($first !== $row) {
                $differing = [];
                foreach ([
                    'désignation' => $first->designation !== $row->designation,
                    'marque' => $first->brand !== $row->brand,
                    'catégorie' => $first->categoryPath !== $row->categoryPath,
                ] as $label => $differs) {
                    if ($differs) {
                        $differing[] = $label;
                    }
                }
                if ([] !== $differing) {
                    $builder->anomaly($row->line, ImportAnomalyCode::ProductFieldsConflict, \sprintf(
                        'Référence « %s » : %s différent(e) de la ligne %d, valeur de la première ligne conservée.',
                        $row->reference, implode(', ', $differing), $first->line,
                    ));
                }
            }

            if (null !== $matched) {
                $matchedId = (int) $matched->getId();
                $present[$matchedId] = true;
                $claimed[$matchedId] = $row->line;
                if (null === $byKey) {
                    $movedKeys[$key] = ['variantId' => $matchedId, 'line' => $row->line];
                }
                if (!$matched->isActive() || $matched->isArchived()) {
                    $builder->reactivate($matchedId);
                }
                $changes = $this->changes($matched, $row, $first);
                if (isset($changes['category']) && null === $matched->getProduct()?->getCategory()
                    && !isset($lockChecked[$row->reference])) {
                    // Première catégorisation : le mode de suivi ne bouge plus dès que le produit a des mouvements.
                    $lockChecked[$row->reference] = true;
                    if (null !== $matched->getProduct() && $this->stockPresence->productHasMovements($matched->getProduct())) {
                        $builder->anomaly($row->line, ImportAnomalyCode::TrackingModeLocked, \sprintf(
                            'Produit %s : mode de suivi conservé, le produit a des mouvements de stock.', $row->reference,
                        ));
                    }
                }
                if ([] !== $changes) {
                    $builder->changed($matchedId, $row->line, $changes);
                    $newBrand = $changes['brand']['to'] ?? null;
                    if (null !== $newBrand && !isset($knownBrands[$newBrand])) {
                        $builder->newBrand($newBrand);
                    }
                }
                $stored = $storedPrices[$matchedId] ?? ['club' => null, 'public' => null];
                $builder->price($matchedId, $row, $stored['club'], $stored['public']);
                continue;
            }

            $newKeys[$key] = $row->line;
            $product = $productByReference[$row->reference] ?? null;
            if (null === $product && !isset($newReferences[$row->reference])) {
                $newReferences[$row->reference] = true;
                $builder->newProduct($first);
                if (!isset($knownBrands[$first->brand])) {
                    $builder->newBrand($first->brand);
                }
            }
            $builder->newVariant($row, $product?->getId());
            $builder->price(null, $row, null, null);
        }

        // Absentes du fichier : désactivées si elles ont du stock, archivées sinon (y compris les variantes
        // désactivées avant l'archivage) ; les variantes déjà archivées restent telles quelles.
        $absent = [];
        foreach ($variants as $variant) {
            if (!isset($present[(int) $variant->getId()]) && !$variant->isArchived()) {
                $absent[(int) $variant->getId()] = $variant;
            }
        }
        $inStock = $this->stockPresence->variantIdsInStock(array_keys($absent));
        foreach ($absent as $id => $variant) {
            if (isset($inStock[$id])) {
                if ($variant->isActive()) {
                    $builder->deactivate($id);
                }
            } else {
                $builder->archive($id);
            }
        }

        return $builder->build();
    }

    /** @return array<string, array{from: string|null, to: string|null}> */
    private function changes(Variant $variant, NormalizedRow $row, NormalizedRow $productRow): array
    {
        $product = $variant->getProduct();
        $pairs = [
            'designation' => [$product?->getDesignation(), $productRow->designation],
            'brand' => [$product?->getBrand()?->getName(), $productRow->brand],
            'size' => [$variant->getSize(), $row->size],
            'fit' => [$variant->getFit(), $row->fit],
            'side' => [$variant->getSide(), $row->side],
            'width' => [$variant->getWidth(), $row->width],
            'color' => [$variant->getColor(), $row->color],
        ];
        // Catégorie vide ou inconnue : la catégorie existante est conservée (aucun changement).
        if ([] !== $productRow->categoryPath) {
            $pairs['category'] = [$product?->getCategory()?->getPath(), implode(' › ', $productRow->categoryPath)];
        }
        // Gencode renseigné pour la première fois sur une variante qui n'en avait pas.
        if (null === $variant->getEan() && null !== $row->ean) {
            $pairs['ean'] = [null, $row->ean];
        }

        $changes = [];
        foreach ($pairs as $field => [$from, $to]) {
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    private function resolvePath(ImportRun $run): string
    {
        $relative = $run->getStoredPath();
        if ('' === $relative || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new \DomainException('Chemin du fichier d\'import invalide.');
        }

        return rtrim($this->importsDir, '/').'/'.$relative;
    }
}
