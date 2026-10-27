<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027193520 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Variant: stable internal HV- code and variant_code_seq';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE variant_code_seq START 1');
        $this->addSql('ALTER TABLE variant ADD code VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE variant v SET code = s.c FROM (SELECT id, 'HV-' || lpad(nextval('variant_code_seq')::text, 6, '0') AS c FROM (SELECT id FROM variant ORDER BY id) o) s WHERE v.id = s.id");
        $this->addSql('ALTER TABLE variant ALTER code SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_variant_code ON variant (code)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_variant_code');
        $this->addSql('ALTER TABLE variant DROP code');
        $this->addSql('DROP SEQUENCE variant_code_seq');
    }
}
