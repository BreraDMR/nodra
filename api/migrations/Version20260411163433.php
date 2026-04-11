<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260411163433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store empty product and variant attributes as {}, remember when a product was first described';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE product SET attributes = '{}' WHERE attributes = '[]'::jsonb");
        $this->addSql("UPDATE product_variant SET attributes = '{}' WHERE attributes = '[]'::jsonb");

        $this->addSql('ALTER TABLE product ADD described_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // anything with a brand or attributes was filled by the import or the admin already;
        // fully blank cards stay null so the import may still fill them once
        $this->addSql("UPDATE product SET described_at = NOW() WHERE brand IS NOT NULL OR attributes <> '{}'::jsonb");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP described_at');
        // {} stays: the previous code reads it the same as [], and which rows were [] isn't known anymore
    }
}
