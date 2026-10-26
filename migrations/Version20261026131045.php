<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cookidoo recipes imported from links, kept so each page is read once.
 */
final class Version20261026131045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cookidoo recipes table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cookidoo_recipes (fetched_at DATETIME NOT NULL, id VARCHAR(40) NOT NULL, name VARCHAR(200) NOT NULL, kcal DOUBLE PRECISION NOT NULL, protein_g DOUBLE PRECISION NOT NULL, carbs_g DOUBLE PRECISION NOT NULL, fat_g DOUBLE PRECISION NOT NULL, grams DOUBLE PRECISION NOT NULL, portions INTEGER NOT NULL, ingredients CLOB NOT NULL, url VARCHAR(300) NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cookidoo_recipes');
    }
}
