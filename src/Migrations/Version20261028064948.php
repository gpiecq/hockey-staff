<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028064948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Détenteur des pièces prêtées ; joueur et équipe des opérations de stock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD holder_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD CONSTRAINT FK_1F1B251EDEEE62D0 FOREIGN KEY (holder_id) REFERENCES player (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1F1B251EDEEE62D0 ON item (holder_id)');
        $this->addSql('ALTER TABLE stock_operation ADD player_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_operation ADD team_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_operation ADD CONSTRAINT FK_811C408599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_operation ADD CONSTRAINT FK_811C4085296CD8AE FOREIGN KEY (team_id) REFERENCES team (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_811C408599E6F5DF ON stock_operation (player_id)');
        $this->addSql('CREATE INDEX IDX_811C4085296CD8AE ON stock_operation (team_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP CONSTRAINT FK_1F1B251EDEEE62D0');
        $this->addSql('DROP INDEX IDX_1F1B251EDEEE62D0');
        $this->addSql('ALTER TABLE item DROP holder_id');
        $this->addSql('ALTER TABLE stock_operation DROP CONSTRAINT FK_811C408599E6F5DF');
        $this->addSql('ALTER TABLE stock_operation DROP CONSTRAINT FK_811C4085296CD8AE');
        $this->addSql('DROP INDEX IDX_811C408599E6F5DF');
        $this->addSql('DROP INDEX IDX_811C4085296CD8AE');
        $this->addSql('ALTER TABLE stock_operation DROP player_id');
        $this->addSql('ALTER TABLE stock_operation DROP team_id');
    }
}
