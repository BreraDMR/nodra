<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809171148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D07.3: ratings of a product from outside sources, typed in by hand, apart from any NODRA review';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE external_rating (id UUID NOT NULL, product_id UUID NOT NULL, source VARCHAR(60) NOT NULL, model VARCHAR(160) NOT NULL, rating NUMERIC(6, 3) NOT NULL, rating_scale NUMERIC(6, 3) NOT NULL, rating_count INT NOT NULL, checked_at DATE NOT NULL, url VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_external_rating_product ON external_rating (product_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_external_rating_source_model ON external_rating (product_id, source, model)');
        $this->addSql('ALTER TABLE external_rating ADD CONSTRAINT FK_external_rating_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE external_rating');
    }
}
