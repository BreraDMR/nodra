<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706192543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D03.3/D03.4: admin field origins, manual feed-row bindings, feed URLs per supplier and the import batch journal';
    }

    public function up(Schema $schema): void
    {
        // an origin row written by the admin has no import run, only the admin's email
        $this->addSql('ALTER TABLE import_field_origin ALTER run_id DROP NOT NULL');
        $this->addSql('ALTER TABLE import_field_origin ADD admin_email VARCHAR(180) DEFAULT NULL');
        // the standing feed of a supplier lives with its other import defaults
        $this->addSql('ALTER TABLE import_supplier_setting ADD feed_url VARCHAR(2048) DEFAULT NULL');
        $this->addSql("CREATE TABLE import_feed_binding (id UUID NOT NULL, variant_id UUID NOT NULL, supplier VARCHAR(32) NOT NULL, supplier_sku VARCHAR(120) NOT NULL, created_by VARCHAR(180) NOT NULL, created_at TIMESTAMPTZ NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_feed_binding_supplier_sku ON import_feed_binding (supplier, supplier_sku)');
        $this->addSql('CREATE INDEX idx_feed_binding_variant ON import_feed_binding (variant_id)');
        $this->addSql('ALTER TABLE import_feed_binding ADD CONSTRAINT fk_feed_binding_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("CREATE TABLE import_batch (id UUID NOT NULL, run_id UUID NOT NULL, supplier VARCHAR(32) NOT NULL, batch_no INT NOT NULL, row_from INT NOT NULL, row_to INT NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 3, plan JSONB NOT NULL DEFAULT '{}', error TEXT DEFAULT NULL, created_at TIMESTAMPTZ NOT NULL, started_at TIMESTAMPTZ DEFAULT NULL, finished_at TIMESTAMPTZ DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_import_batch_run ON import_batch (run_id, batch_no)');
        $this->addSql('CREATE INDEX idx_import_batch_status ON import_batch (supplier, status)');
        $this->addSql('ALTER TABLE import_batch ADD CONSTRAINT fk_import_batch_run FOREIGN KEY (run_id) REFERENCES import_run (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_batch');
        $this->addSql('DROP TABLE import_feed_binding');
        $this->addSql('ALTER TABLE import_supplier_setting DROP feed_url');
        // admin-written origins have no run and cannot survive the column going back to NOT NULL
        $this->addSql("DELETE FROM import_field_origin WHERE run_id IS NULL");
        $this->addSql('ALTER TABLE import_field_origin DROP admin_email');
        $this->addSql('ALTER TABLE import_field_origin ALTER run_id SET NOT NULL');
    }
}
