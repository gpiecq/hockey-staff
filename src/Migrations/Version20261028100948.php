<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ventes d'occasion en quantité : une ligne de vente porte variante × état × quantité (prix unitaires figés)
 * au lieu d'une pièce. Aucune vente n'existe encore : la migration refuse de tourner sur une table non vide
 * plutôt que d'inventer une variante, un état et une quantité.
 */
final class Version20261028100948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sale lines by variant, condition and quantity instead of item';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->hasSaleLines(), 'sale_line contient des lignes : reprise des ventes par pièce non prévue.');

        $this->addSql('ALTER TABLE sale_line DROP CONSTRAINT fk_6d5ac013126f525e');
        $this->addSql('DROP INDEX uniq_sale_line_item');
        $this->addSql('ALTER TABLE sale_line DROP item_id');
        $this->addSql('ALTER TABLE sale_line ADD variant_id INT NOT NULL');
        $this->addSql('ALTER TABLE sale_line ADD condition VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE sale_line ADD quantity INT NOT NULL');
        $this->addSql('ALTER TABLE sale_line ADD CONSTRAINT chk_sale_line_quantity CHECK (quantity >= 1)');
        $this->addSql('ALTER TABLE sale_line ADD CONSTRAINT FK_6D5AC0133B69A9AF FOREIGN KEY (variant_id) REFERENCES variant (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6D5AC0133B69A9AF ON sale_line (variant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->hasSaleLines(), 'sale_line contient des lignes : retour aux ventes par pièce impossible.');

        $this->addSql('ALTER TABLE sale_line DROP CONSTRAINT FK_6D5AC0133B69A9AF');
        $this->addSql('DROP INDEX IDX_6D5AC0133B69A9AF');
        $this->addSql('ALTER TABLE sale_line DROP CONSTRAINT chk_sale_line_quantity');
        $this->addSql('ALTER TABLE sale_line DROP quantity');
        $this->addSql('ALTER TABLE sale_line DROP condition');
        $this->addSql('ALTER TABLE sale_line DROP variant_id');
        $this->addSql('ALTER TABLE sale_line ADD item_id INT NOT NULL');
        $this->addSql('ALTER TABLE sale_line ADD CONSTRAINT fk_6d5ac013126f525e FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX uniq_sale_line_item ON sale_line (item_id)');
    }

    private function hasSaleLines(): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM sale_line LIMIT 1');
    }
}
