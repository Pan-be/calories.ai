<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Meals from a recipe remember which recipe, so "recently eaten" finds the exact one
 * (some recipe names repeat with different values).
 */
final class Version20261026122657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meals: recipe_id for meals logged from a recipe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meals ADD COLUMN recipe_id VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__meals AS
            SELECT
              id,
              eaten_at,
              day,
              name,
              source,
              photo,
              description,
              health_score,
              confidence,
              notes,
              ai_model,
              analysis,
              created_at,
              updated_at,
              user_id
            FROM
              meals
        SQL);
        $this->addSql('DROP TABLE meals');
        $this->addSql(<<<'SQL'
            CREATE TABLE meals (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              eaten_at DATETIME NOT NULL,
              day VARCHAR(10) NOT NULL,
              name VARCHAR(200) NOT NULL,
              source VARCHAR(10) NOT NULL,
              photo VARCHAR(40) DEFAULT NULL,
              description CLOB DEFAULT NULL,
              health_score INTEGER DEFAULT NULL,
              confidence DOUBLE PRECISION DEFAULT NULL,
              notes CLOB DEFAULT NULL,
              ai_model VARCHAR(60) DEFAULT NULL,
              analysis CLOB DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              user_id INTEGER NOT NULL,
              CONSTRAINT FK_E229E6EAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO meals (
              id, eaten_at, day, name, source, photo,
              description, health_score, confidence,
              notes, ai_model, analysis, created_at,
              updated_at, user_id
            )
            SELECT
              id,
              eaten_at,
              day,
              name,
              source,
              photo,
              description,
              health_score,
              confidence,
              notes,
              ai_model,
              analysis,
              created_at,
              updated_at,
              user_id
            FROM
              __temp__meals
        SQL);
        $this->addSql('DROP TABLE __temp__meals');
        $this->addSql('CREATE INDEX IDX_E229E6EAA76ED395E5A02990 ON meals (user_id, day)');
        $this->addSql('CREATE INDEX IDX_E229E6EAA76ED395 ON meals (user_id)');
    }
}
