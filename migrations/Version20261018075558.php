<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Several workouts per day: the one-per-day unique index becomes a plain index, plus an
 * optional part of day. Down fails while a day has more than one entry.
 */
final class Version20261018075558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workouts: several per day, optional part of day';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__workouts AS
            SELECT
              id,
              rest,
              description,
              intensity,
              updated_at,
              day,
              user_id
            FROM
              workouts
        SQL);
        $this->addSql('DROP TABLE workouts');
        $this->addSql(<<<'SQL'
            CREATE TABLE workouts (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              rest BOOLEAN NOT NULL,
              description VARCHAR(500) DEFAULT NULL,
              intensity VARCHAR(10) DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              day VARCHAR(10) NOT NULL,
              user_id INTEGER NOT NULL,
              part_of_day VARCHAR(10) DEFAULT NULL,
              CONSTRAINT FK_A56140E0A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON
              UPDATE
                NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO workouts (
              id, rest, description, intensity, updated_at,
              day, user_id
            )
            SELECT
              id,
              rest,
              description,
              intensity,
              updated_at,
              day,
              user_id
            FROM
              __temp__workouts
        SQL);
        $this->addSql('DROP TABLE __temp__workouts');
        $this->addSql('CREATE INDEX IDX_A56140E0A76ED395 ON workouts (user_id)');
        $this->addSql('CREATE INDEX IDX_A56140E0A76ED395E5A02990 ON workouts (user_id, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__workouts AS
            SELECT
              id,
              rest,
              description,
              intensity,
              updated_at,
              day,
              user_id
            FROM
              workouts
        SQL);
        $this->addSql('DROP TABLE workouts');
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
        $this->addSql(<<<'SQL'
            INSERT INTO workouts (
              id, rest, description, intensity, updated_at,
              day, user_id
            )
            SELECT
              id,
              rest,
              description,
              intensity,
              updated_at,
              day,
              user_id
            FROM
              __temp__workouts
        SQL);
        $this->addSql('DROP TABLE __temp__workouts');
        $this->addSql('CREATE INDEX IDX_A56140E0A76ED395 ON workouts (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A56140E0A76ED395E5A02990 ON workouts (user_id, day)');
    }
}
