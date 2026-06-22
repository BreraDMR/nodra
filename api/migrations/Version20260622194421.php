<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622194421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D03.2: import runs with field origins, per-supplier import defaults, the offer supplier SKU and the category seed marker';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE import_run (id UUID NOT NULL, source VARCHAR(32) NOT NULL, file_name VARCHAR(255) DEFAULT NULL, file_sha256 VARCHAR(64) DEFAULT NULL, status VARCHAR(16) NOT NULL, total_rows INT NOT NULL DEFAULT 0, counts JSONB NOT NULL DEFAULT '{}', report JSONB DEFAULT NULL, errors JSONB NOT NULL DEFAULT '[]', started_at TIMESTAMPTZ NOT NULL, finished_at TIMESTAMPTZ DEFAULT NULL, admin_email VARCHAR(180) DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_import_run_started ON import_run (started_at)');
        $this->addSql("CREATE TABLE import_field_origin (id UUID NOT NULL, run_id UUID NOT NULL, entity_type VARCHAR(32) NOT NULL, entity_id UUID NOT NULL, field VARCHAR(64) NOT NULL, written_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_import_origin_entity ON import_field_origin (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_import_origin_run ON import_field_origin (run_id)');
        $this->addSql('ALTER TABLE import_field_origin ADD CONSTRAINT fk_import_origin_run FOREIGN KEY (run_id) REFERENCES import_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE import_supplier_setting (supplier VARCHAR(32) NOT NULL, inbound_shipping_minor INT NOT NULL DEFAULT 0, PRIMARY KEY(supplier))');
        $this->addSql('ALTER TABLE supplier_offer ADD supplier_sku VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_supplier_offer_supplier_sku ON supplier_offer (supplier, supplier_sku)');
        $this->addSql('ALTER TABLE category ADD seeded_at TIMESTAMPTZ DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_field_origin');
        $this->addSql('DROP TABLE import_supplier_setting');
        $this->addSql('DROP TABLE import_run');
        $this->addSql('DROP INDEX idx_supplier_offer_supplier_sku');
        $this->addSql('ALTER TABLE supplier_offer DROP supplier_sku');
        $this->addSql('ALTER TABLE category DROP seeded_at');
    }
}
