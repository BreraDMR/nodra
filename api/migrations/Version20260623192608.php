<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623192608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D03.2: the import run remembers its supplier and products keep the feed image references';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run ADD supplier VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE product ADD source_images JSONB NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run DROP supplier');
        $this->addSql('ALTER TABLE product DROP source_images');
    }
}
