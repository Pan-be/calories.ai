<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Weighings from the Bluetooth scale, with the raw impedance frame.
 */
final class Version20261027075413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scale measurements table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE scale_measurements (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              measured_at DATETIME NOT NULL,
              day VARCHAR(10) NOT NULL,
              frame VARCHAR(236) DEFAULT NULL,
              weight_kg DOUBLE PRECISION NOT NULL,
              user_id INTEGER NOT NULL,
              CONSTRAINT FK_D8ABB0B2A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_D8ABB0B2A76ED395E5A02990 ON scale_measurements (user_id, day)');
        $this->addSql('CREATE INDEX IDX_D8ABB0B2A76ED395 ON scale_measurements (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scale_measurements');
    }
}
