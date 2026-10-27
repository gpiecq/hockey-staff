<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Brand;
use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\SupplierImportMapping;
use App\Entity\Variant;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Jeu de données d'import pour les tests : la migration de données Bauer est ignorée en environnement de test.
 * Colonnes et alias identiques à ceux de la migration Version20261027130100.
 */
final class ImportFixtures
{
    public const array BAUER_COLUMNS = [
        'reference' => 'Référence interne',
        'ean' => 'Gencode',
        'designation' => 'Désignation',
        'size' => 'Taille',
        'fit' => 'Modèle/Fit',
        'side' => 'Côté',
        'width' => 'Largeur',
        'color' => 'Couleur',
        'publicPriceTtc' => 'PU Public TTC',
        'clubPriceTtc' => 'PU Club TTC',
        'category1' => 'Catégorie',
        'category2' => 'sous-catégorie 1',
        'category3' => 'sous-catégorie 2',
        'brand' => 'Marque',
    ];

    public const array BAUER_COLOR_ALIASES = [
        'BLK' => 'NOIR',
        'WHT' => 'BLANC',
        'NAVY' => 'MARINE',
        'NAV' => 'MARINE',
        'RED' => 'ROUGE',
    ];

    public static function supplierWithMapping(EntityManagerInterface $em, string $name = 'Bauer'): Supplier
    {
        $supplier = (new Supplier())->setName($name);
        $em->persist($supplier);
        $mapping = (new SupplierImportMapping($supplier))
            ->setColumns(self::BAUER_COLUMNS)
            ->setColorAliases(self::BAUER_COLOR_ALIASES);
        $em->persist($mapping);
        $em->flush();

        return $supplier;
    }

    public static function season(EntityManagerInterface $em, string $label = '2026-2027'): Season
    {
        $season = new Season($label, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-30'));
        $em->persist($season);
        $em->flush();

        return $season;
    }

    /** @param list<string> $categoryPath */
    public static function product(
        EntityManagerInterface $em,
        Supplier $supplier,
        string $reference,
        string $designation = 'Patin Vapor',
        string $brand = 'Bauer',
        array $categoryPath = ['EQUIPEMENT', 'JOUEUR'],
    ): Product {
        $brandEntity = $em->getRepository(Brand::class)->findOneBy(['name' => mb_strtoupper($brand)]);
        if (null === $brandEntity) {
            $brandEntity = (new Brand())->setName($brand);
            $em->persist($brandEntity);
        }
        $categories = $em->getRepository(\App\Entity\Category::class);
        \assert($categories instanceof CategoryRepository);
        $product = (new Product())
            ->setSupplier($supplier)
            ->setReference($reference)
            ->setDesignation($designation)
            ->setBrand($brandEntity)
            ->setCategory($categories->findOrCreatePath($categoryPath));
        $em->persist($product);
        $em->flush();

        return $product;
    }

    public static function variant(
        EntityManagerInterface $em,
        Product $product,
        ?string $ean,
        ?string $size = null,
        bool $active = true,
        ?Season $season = null,
        ?int $club = null,
        ?int $public = null,
        ?string $color = null,
    ): Variant {
        $variant = (new Variant())->setEan($ean)->setSize($size)->setColor($color)->setActive($active);
        $product->addVariant($variant);
        $em->persist($variant);
        if (null !== $season && (null !== $club || null !== $public)) {
            $em->persist((new SeasonPrice())->setVariant($variant)->setSeason($season)->setClubPriceTtc($club)->setPublicPriceTtc($public));
        }
        $em->flush();

        return $variant;
    }

    /**
     * Écrit le fichier dans var/storage/imports/ et crée le run (storedPath relatif à ce dossier).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null          $headers
     */
    public static function run(EntityManagerInterface $em, Supplier $supplier, Season $season, array $rows, ?array $headers = null): ImportRun
    {
        $tmp = CatalogueSpreadsheetFactory::create($rows, $headers);
        $dir = \dirname(__DIR__, 2).'/var/storage/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        $name = 'test_'.bin2hex(random_bytes(6)).'.xlsx';
        rename($tmp, $dir.'/'.$name);

        $run = new ImportRun($supplier, $season, 'catalogue.xlsx', $name);
        $em->persist($run);
        $em->flush();

        return $run;
    }

    public static function cleanup(): void
    {
        foreach (glob(\dirname(__DIR__, 2).'/var/storage/imports/test_*.xlsx') ?: [] as $file) {
            @unlink($file);
        }
    }
}
