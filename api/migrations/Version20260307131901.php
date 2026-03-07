<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260307131901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store dated supplier offers separately from NODRA inventory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE supplier_offer (id UUID NOT NULL, product_id UUID NOT NULL, supplier VARCHAR(32) NOT NULL, url VARCHAR(2048) NOT NULL, title VARCHAR(200) NOT NULL, currency VARCHAR(3) NOT NULL, price_minor INT NOT NULL, reported_quantity INT DEFAULT NULL, checked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, verification_status VARCHAR(24) NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_supplier_offer_product ON supplier_offer (product_id, checked_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_supplier_offer_product_url ON supplier_offer (product_id, url)');
        $this->addSql('ALTER TABLE supplier_offer ADD CONSTRAINT FK_supplier_offer_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE supplier_offer');
    }
}
