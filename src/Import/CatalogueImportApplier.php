<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalogue\NameNormalizer;
use App\Catalogue\TrackingModeResolver;
use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\SeasonPrice;
use App\Entity\Variant;
use App\Enum\ImportRunStatus;
use App\Enum\TrackingMode;
use App\Import\Exception\ImportNotApplicableException;
use App\Import\Exception\StaleImportException;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applique un import validé : le fichier est ré-analysé dans la transaction et c'est ce résultat qui est écrit
 * (le diff stocké sur le run n'est qu'un aperçu).
 */
final class CatalogueImportApplier
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogueImportAnalyzer $analyzer,
        private readonly CategoryRepository $categories,
        private readonly TrackingModeResolver $trackingModes,
        #[Autowire('%app.imports_dir%')]
        private readonly string $importsDir,
    ) {
    }

    /**
     * @throws ImportNotApplicableException si le run n'est pas à l'état Analyzed
     * @throws StaleImportException         si un autre import du même fournisseur a été appliqué depuis l'analyse
     */
    public function apply(ImportRun $run): ImportDiff
    {
        if (ImportRunStatus::Analyzed !== $run->getStatus()) {
            throw new ImportNotApplicableException('Seul un import analysé peut être appliqué.');
        }
        // Contrôle préalable hors transaction : un refus ordinaire ne ferme pas l'EntityManager.
        $this->assertNotStale($run);

        $diff = $this->em->wrapInTransaction(function () use ($run): ImportDiff {
            // Verrou sur le fournisseur : deux applications concurrentes sont sérialisées, la seconde voit la première.
            $this->em->lock($run->getSupplier(), LockMode::PESSIMISTIC_WRITE);
            // Un appel concurrent a pu appliquer ce run entre le contrôle préalable et le verrou : on relit son état.
            $this->em->refresh($run);
            if (ImportRunStatus::Analyzed !== $run->getStatus()) {
                throw new ImportNotApplicableException('Seul un import analysé peut être appliqué.');
            }
            $this->assertNotStale($run);

            $diff = $this->analyzer->analyze($run);
            $this->write($run, $diff);
            $run->markApplied($diff->toArray());
            $this->em->flush();

            return $diff;
        });

        return $diff;
    }

    public function cancel(ImportRun $run): void
    {
        if (\in_array($run->getStatus(), [ImportRunStatus::Applied, ImportRunStatus::Cancelled], true)) {
            throw new \LogicException('Cet import ne peut plus être annulé.');
        }

        $run->markCancelled();
        $this->em->flush();

        $relative = $run->getStoredPath();
        if ('' !== $relative && !str_contains($relative, '..') && !str_starts_with($relative, '/')) {
            $path = rtrim($this->importsDir, '/').'/'.$relative;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Les colonnes ont une précision à la seconde : une application dans la même seconde que l'analyse est
     * comptée comme postérieure (refus prudent, il suffit de relancer l'analyse).
     */
    private function assertNotStale(ImportRun $run): void
    {
        $analyzedAt = $run->getAnalyzedAt();
        if (null === $analyzedAt) {
            return;
        }
        $stale = (int) $this->em->createQuery(
            'SELECT COUNT(r.id) FROM App\Entity\ImportRun r
             WHERE r.supplier = :supplier AND r.status = :status AND r.appliedAt >= :analyzedAt AND r != :run',
        )
            ->setParameter('supplier', $run->getSupplier())
            ->setParameter('status', ImportRunStatus::Applied)
            ->setParameter('analyzedAt', $analyzedAt)
            ->setParameter('run', $run)
            ->getSingleScalarResult();
        if ($stale > 0) {
            throw new StaleImportException();
        }
    }

    private function write(ImportRun $run, ImportDiff $diff): void
    {
        $supplier = $run->getSupplier();
        $season = $run->getSeason();

        /** @var list<Variant> $supplierVariants */
        $supplierVariants = $this->em->createQuery(
            'SELECT v, p FROM App\Entity\Variant v JOIN v.product p WHERE p.supplier = :supplier',
        )->setParameter('supplier', $supplier)->getResult();
        $variants = [];
        foreach ($supplierVariants as $variant) {
            $variants[(int) $variant->getId()] = $variant;
        }
        unset($supplierVariants);

        /** @var list<SeasonPrice> $seasonPrices */
        $seasonPrices = $this->em->createQuery(
            'SELECT sp FROM App\Entity\SeasonPrice sp JOIN sp.variant v JOIN v.product p
             WHERE sp.season = :season AND p.supplier = :supplier',
        )->setParameter('season', $season)->setParameter('supplier', $supplier)->getResult();
        $pricesByVariant = [];
        foreach ($seasonPrices as $seasonPrice) {
            $pricesByVariant[(int) $seasonPrice->getVariant()?->getId()] = $seasonPrice;
        }
        unset($seasonPrices);

        $brands = [];
        $brandFor = function (string $name) use (&$brands): Brand {
            $normalized = NameNormalizer::normalize($name);

            return $brands[$normalized] ??= $this->findOrCreateBrand($normalized);
        };
        $categoriesByPath = [];
        // Jamais de création : un chemin vide ou devenu introuvable (catégorie supprimée depuis l'analyse) donne null.
        $categoryFor = function (array $path) use (&$categoriesByPath): ?Category {
            if ([] === $path) {
                return null;
            }
            $key = implode(' › ', $path);
            if (!\array_key_exists($key, $categoriesByPath)) {
                $categoriesByPath[$key] = $this->categories->findByPath(array_values($path));
            }

            return $categoriesByPath[$key];
        };

        $operations = 0;
        $tick = function () use (&$operations): void {
            if (0 === ++$operations % self::BATCH_SIZE) {
                $this->em->flush();
            }
        };

        // 1. Nouveaux produits
        /** @var array<string, Product> $createdProducts */
        $createdProducts = [];
        foreach ($diff->newProducts as $new) {
            $category = $categoryFor($new['categoryPath']);
            $product = (new Product())
                ->setSupplier($supplier)
                ->setReference($new['reference'])
                ->setDesignation($new['designation'])
                ->setBrand($brandFor($new['brand']))
                ->setCategory($category)
                ->setTrackingMode(null === $category ? TrackingMode::Quantity : $this->trackingModes->defaultFor($category));
            $this->em->persist($product);
            $createdProducts[$new['reference']] = $product;
            $tick();
        }

        // 2. Nouvelles variantes
        /** @var array<int, Variant> $newByLine */
        $newByLine = [];
        foreach ($diff->newVariants as $new) {
            $product = null === $new['productId']
                ? ($createdProducts[$new['reference']] ?? throw new \LogicException(\sprintf('Produit « %s » introuvable pour la ligne %d.', $new['reference'], $new['line'])))
                : ($this->em->getReference(Product::class, $new['productId']) ?? throw new \LogicException(\sprintf('Produit %d introuvable.', $new['productId'])));
            $variant = (new Variant())
                ->setEan($new['ean'])
                ->setSize($new['size'])
                ->setFit($new['fit'])
                ->setSide($new['side'])
                ->setWidth($new['width'])
                ->setColor($new['color'])
                ->setActive(true);
            $product->addVariant($variant);
            $this->em->persist($variant);
            $newByLine[$new['line']] = $variant;
            $tick();
        }

        // 3. Variantes modifiées (champs du produit répétés sur chaque variante : écrits une seule fois)
        $productsDone = [];
        foreach ($diff->changedVariants as $changed) {
            $variant = $variants[$changed['variantId']] ?? throw new \LogicException(\sprintf('Variante %d introuvable.', $changed['variantId']));
            $product = $variant->getProduct() ?? throw new \LogicException('Variante sans produit.');
            $changes = $changed['changes'];
            if (!isset($productsDone[(int) $product->getId()])) {
                $productsDone[(int) $product->getId()] = true;
                if (isset($changes['designation']) && null !== $changes['designation']['to']) {
                    $product->setDesignation($changes['designation']['to']);
                }
                if (isset($changes['brand']) && null !== $changes['brand']['to']) {
                    $product->setBrand($brandFor($changes['brand']['to']));
                }
                if (isset($changes['category']) && null !== $changes['category']['to']) {
                    $category = $categoryFor(explode(' › ', $changes['category']['to']));
                    if (null !== $category) {
                        $product->setCategory($category);
                    }
                }
            }
            foreach ($changes as $field => $change) {
                match ($field) {
                    'size' => $variant->setSize($change['to']),
                    'fit' => $variant->setFit($change['to']),
                    'side' => $variant->setSide($change['to']),
                    'width' => $variant->setWidth($change['to']),
                    'color' => $variant->setColor($change['to']),
                    'ean' => $variant->setEan($change['to']),
                    default => null,
                };
            }
            $tick();
        }

        // 4. Tarifs de la saison : seuls les côtés modifiés sont écrits (un prix absent du fichier n'efface rien)
        foreach ($diff->priceChanges as $change) {
            $variant = null === $change['variantId'] ? ($newByLine[$change['line']] ?? null) : ($variants[$change['variantId']] ?? null);
            if (null === $variant) {
                throw new \LogicException(\sprintf('Variante introuvable pour le tarif de la ligne %d.', $change['line']));
            }
            $price = null === $change['variantId'] ? null : ($pricesByVariant[$change['variantId']] ?? null);
            if (null === $price) {
                $price = (new SeasonPrice())->setVariant($variant)->setSeason($season);
                $this->em->persist($price);
            }
            if (null !== $change['club']['to'] && $change['club']['from'] !== $change['club']['to']) {
                $price->setClubPriceTtc($change['club']['to']);
            }
            if (null !== $change['public']['to'] && $change['public']['from'] !== $change['public']['to']) {
                $price->setPublicPriceTtc($change['public']['to']);
            }
            $tick();
        }

        // 5. Désactivations (jamais de suppression) et réactivations
        foreach ($diff->deactivatedVariantIds as $id) {
            ($variants[$id] ?? throw new \LogicException(\sprintf('Variante %d introuvable.', $id)))->setActive(false);
            $tick();
        }
        foreach ($diff->reactivatedVariantIds as $id) {
            ($variants[$id] ?? throw new \LogicException(\sprintf('Variante %d introuvable.', $id)))->setActive(true);
            $tick();
        }

        $this->em->flush();
    }

    private function findOrCreateBrand(string $name): Brand
    {
        $brand = $this->em->getRepository(Brand::class)->findOneBy(['name' => $name]);
        if (null === $brand) {
            $brand = (new Brand())->setName($name);
            $this->em->persist($brand);
        }

        return $brand;
    }
}
