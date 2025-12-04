<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251204194155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalogue, inventory, administration and order tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(120) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AD8A54A9E7927C74 ON admin_user (email)');
        $this->addSql('CREATE TABLE order_item (id UUID NOT NULL, product_name VARCHAR(160) NOT NULL, variant_label VARCHAR(120) NOT NULL, sku VARCHAR(80) NOT NULL, quantity INT NOT NULL, unit_price_minor INT NOT NULL, line_total_minor INT NOT NULL, order_id UUID NOT NULL, variant_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_order_item_order ON order_item (order_id)');
        $this->addSql('CREATE INDEX IDX_52EA1F093B69A9AF ON order_item (variant_id)');
        $this->addSql('CREATE TABLE product (id UUID NOT NULL, slug VARCHAR(120) NOT NULL, category VARCHAR(40) NOT NULL, status VARCHAR(16) NOT NULL, copy JSONB NOT NULL, image VARCHAR(255) NOT NULL, images JSONB NOT NULL, badge VARCHAR(40) DEFAULT NULL, featured_rank INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04AD989D9B62 ON product (slug)');
        $this->addSql('CREATE INDEX idx_product_browse ON product (status, category, featured_rank)');
        $this->addSql('CREATE TABLE product_variant (id UUID NOT NULL, sku VARCHAR(80) NOT NULL, label JSONB NOT NULL, color VARCHAR(30) DEFAULT NULL, size VARCHAR(20) DEFAULT NULL, price_czk INT NOT NULL, price_eur INT NOT NULL, stock INT NOT NULL, active BOOLEAN NOT NULL, product_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_209AA41DF9038C4 ON product_variant (sku)');
        $this->addSql('CREATE INDEX idx_variant_product ON product_variant (product_id, active)');
        $this->addSql('CREATE INDEX IDX_209AA41D4584665A ON product_variant (product_id)');
        $this->addSql('CREATE TABLE shop_order (id UUID NOT NULL, reference VARCHAR(20) NOT NULL, idempotency_key VARCHAR(80) NOT NULL, lookup_token VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, locale VARCHAR(2) NOT NULL, currency VARCHAR(3) NOT NULL, customer_name VARCHAR(160) NOT NULL, email VARCHAR(180) NOT NULL, country VARCHAR(2) NOT NULL, address VARCHAR(255) NOT NULL, postal_code VARCHAR(24) NOT NULL, subtotal_minor INT NOT NULL, shipping_minor INT NOT NULL, total_minor INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_323FC9CAAEA34913 ON shop_order (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_323FC9CA7FD1C147 ON shop_order (idempotency_key)');
        $this->addSql('CREATE INDEX idx_order_created ON shop_order (created_at)');
        $this->addSql('CREATE INDEX idx_order_status ON shop_order (status, created_at)');
        $this->addSql('CREATE TABLE stock_movement (id UUID NOT NULL, delta INT NOT NULL, reason VARCHAR(200) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, variant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_stock_movement_variant ON stock_movement (variant_id, created_at)');
        $this->addSql('CREATE INDEX IDX_BB1BC1B53B69A9AF ON stock_movement (variant_id)');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F098D9F6D38 FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F093B69A9AF FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B53B69A9AF FOREIGN KEY (variant_id) REFERENCES product_variant (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT chk_variant_stock_nonnegative CHECK (stock >= 0)');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT chk_variant_price_nonnegative CHECK (price_czk >= 0 AND price_eur >= 0)');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT chk_order_item_positive CHECK (quantity > 0 AND unit_price_minor >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F098D9F6D38');
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F093B69A9AF');
        $this->addSql('ALTER TABLE product_variant DROP CONSTRAINT FK_209AA41D4584665A');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B53B69A9AF');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE order_item');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_variant');
        $this->addSql('DROP TABLE shop_order');
        $this->addSql('DROP TABLE stock_movement');
    }
}
