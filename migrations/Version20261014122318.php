<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Workout log.
 */
final class Version20261014122318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workouts: training or rest day per user and day';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE workouts (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              rest BOOLEAN NOT NULL,
              description VARCHAR(500) DEFAULT NULL,
              intensity VARCHAR(10) DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              day VARCHAR(10) NOT NULL,
              user_id INTEGER NOT NULL,
              CONSTRAINT FK_A56140E0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A56140E0A76ED395E5A02990 ON workouts (user_id, day)');
        $this->addSql('CREATE INDEX IDX_A56140E0A76ED395 ON workouts (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workouts');
    }
}
