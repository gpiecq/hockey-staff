<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Données : fournisseur BAUER (réutilisé s'il existe déjà) et son mapping d'import.
 * Idempotente et sans effet sur les données existantes (R2 : dates = NOW(), auteurs NULL).
 */
final class Version20261027130100 extends AbstractMigration
{
    private const array COLUMNS = [
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

    private const array COLOR_ALIASES = [
        'BLK' => 'NOIR',
        'WHT' => 'BLANC',
        'NAVY' => 'MARINE',
        'NAV' => 'MARINE',
        'RED' => 'ROUGE',
    ];

    public function getDescription(): string
    {
        return 'Fournisseur BAUER et mapping d\'import par défaut';
    }

    public function up(Schema $schema): void
    {
        // La base de test doit rester vide : les tests fonctionnels créent leurs propres fournisseurs (dont « Bauer »).
        $this->skipIf('test' === ($_SERVER['APP_ENV'] ?? null), 'Données de référence ignorées en environnement de test.');

        $this->addSql(
            "INSERT INTO supplier (name, created_at, updated_at)
             SELECT 'BAUER', NOW(), NOW()
             WHERE NOT EXISTS (SELECT 1 FROM supplier WHERE name = 'BAUER' AND deleted_at IS NULL)",
        );
        $this->addSql(
            "INSERT INTO supplier_import_mapping (supplier_id, source_columns, color_aliases, created_at, updated_at)
             SELECT s.id, ?, ?, NOW(), NOW()
             FROM supplier s
             WHERE s.name = 'BAUER' AND s.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM supplier_import_mapping m WHERE m.supplier_id = s.id)",
            [
                json_encode(self::COLUMNS, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                json_encode(self::COLOR_ALIASES, \JSON_THROW_ON_ERROR),
            ],
        );
    }

    /** Retire uniquement le mapping : le fournisseur peut porter d'autres données. */
    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM supplier_import_mapping
             WHERE supplier_id IN (SELECT id FROM supplier WHERE name = 'BAUER' AND deleted_at IS NULL)",
        );
    }
}
