<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028093716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace product tracking mode (serialized/quantity) with kind (equipment/consumable).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product RENAME COLUMN tracking_mode TO kind');
        $this->addSql("UPDATE product SET kind = CASE kind WHEN 'serialized' THEN 'equipment' ELSE 'consumable' END");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product RENAME COLUMN kind TO tracking_mode');
        $this->addSql("UPDATE product SET tracking_mode = CASE tracking_mode WHEN 'equipment' THEN 'serialized' ELSE 'quantity' END");
    }
}
