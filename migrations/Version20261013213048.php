<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema.
 */
final class Version20261013213048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, tokens, meals with items, weight log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, hash VARCHAR(64) NOT NULL, label VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_2CAD560EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2CAD560ED1B862B8 ON api_tokens (hash)');
        $this->addSql('CREATE INDEX IDX_2CAD560EA76ED395 ON api_tokens (user_id)');
        $this->addSql('CREATE TABLE meal_items (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, position INTEGER NOT NULL, name VARCHAR(200) NOT NULL, portion VARCHAR(100) NOT NULL, grams DOUBLE PRECISION NOT NULL, kcal DOUBLE PRECISION NOT NULL, protein_g DOUBLE PRECISION NOT NULL, carbs_g DOUBLE PRECISION NOT NULL, fat_g DOUBLE PRECISION NOT NULL, meal_id INTEGER NOT NULL, CONSTRAINT FK_5BD4019B639666D6 FOREIGN KEY (meal_id) REFERENCES meals (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5BD4019B639666D6 ON meal_items (meal_id)');
        $this->addSql('CREATE TABLE meals (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, eaten_at DATETIME NOT NULL, day VARCHAR(10) NOT NULL, name VARCHAR(200) NOT NULL, source VARCHAR(10) NOT NULL, photo VARCHAR(40) DEFAULT NULL, description CLOB DEFAULT NULL, health_score INTEGER DEFAULT NULL, confidence DOUBLE PRECISION DEFAULT NULL, notes CLOB DEFAULT NULL, ai_model VARCHAR(60) DEFAULT NULL, analysis CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_E229E6EAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E229E6EAA76ED395E5A02990 ON meals (user_id, day)');
        $this->addSql('CREATE INDEX IDX_E229E6EAA76ED395 ON meals (user_id)');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, timezone VARCHAR(64) NOT NULL, sex VARCHAR(10) DEFAULT NULL, birth_date DATE DEFAULT NULL, height_cm DOUBLE PRECISION DEFAULT NULL, activity VARCHAR(20) DEFAULT NULL, goal VARCHAR(10) DEFAULT NULL, target_weight_kg DOUBLE PRECISION DEFAULT NULL, goal_kcal INTEGER NOT NULL, goal_protein_g INTEGER NOT NULL, goal_carbs_g INTEGER NOT NULL, goal_fat_g INTEGER NOT NULL, goals_manual BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE TABLE weight_entries (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day VARCHAR(10) NOT NULL, weight_kg DOUBLE PRECISION NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_D23C87A1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D23C87A1A76ED395E5A02990 ON weight_entries (user_id, day)');
        $this->addSql('CREATE INDEX IDX_D23C87A1A76ED395 ON weight_entries (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_tokens');
        $this->addSql('DROP TABLE meal_items');
        $this->addSql('DROP TABLE meals');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE weight_entries');
    }
}
