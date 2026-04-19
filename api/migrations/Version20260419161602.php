<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419161602 extends AbstractMigration
{
    /** Default markup bands for all categories, [min, max, markup] in haléře and basis points. The owner tunes them later. */
    private const DEFAULT_RULES = [
        [0, 30000, 6000],
        [30000, 100000, 4000],
        [100000, 300000, 3000],
        [300000, null, 2000],
    ];

    public function getDescription(): string
    {
        return 'D02: offer inbound shipping and exchange rate, variant RRP and market price, pricing rules, own price history, order line sourcing snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_offer ADD inbound_shipping_minor INT DEFAULT 0 NOT NULL, ADD fx_rate_czk INT DEFAULT NULL, ADD fx_rate_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier_offer ADD CONSTRAINT chk_supplier_offer_cost CHECK (inbound_shipping_minor >= 0 AND (fx_rate_czk IS NULL OR fx_rate_czk > 0))');
        // a CZK price needs no conversion; other currencies wait until the admin enters the rate
        $this->addSql("UPDATE supplier_offer SET fx_rate_czk = 1000000 WHERE currency = 'CZK'");

        $this->addSql('ALTER TABLE product_variant ADD rrp_minor INT DEFAULT NULL, ADD rrp_currency VARCHAR(3) DEFAULT NULL, ADD rrp_source VARCHAR(500) DEFAULT NULL, ADD rrp_checked_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE product_variant ADD market_price_minor INT DEFAULT NULL, ADD market_price_source VARCHAR(500) DEFAULT NULL, ADD market_checked_at DATE DEFAULT NULL');
        $this->addSql("ALTER TABLE product_variant ADD CONSTRAINT chk_variant_rrp CHECK (rrp_minor IS NULL OR (rrp_minor > 0 AND rrp_currency IN ('CZK', 'EUR')))");
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT chk_variant_market_price CHECK (market_price_minor IS NULL OR market_price_minor > 0)');

        $this->addSql('CREATE TABLE pricing_rule (id UUID NOT NULL, min_cost_czk_minor INT DEFAULT 0 NOT NULL, max_cost_czk_minor INT DEFAULT NULL, markup_bp INT NOT NULL, active BOOLEAN DEFAULT true NOT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_pricing_rule_category ON pricing_rule (category_id)');
        $this->addSql('ALTER TABLE pricing_rule ADD CONSTRAINT FK_6DCEA67212469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pricing_rule ADD CONSTRAINT chk_pricing_rule_band CHECK (min_cost_czk_minor >= 0 AND (max_cost_czk_minor IS NULL OR max_cost_czk_minor > min_cost_czk_minor) AND markup_bp >= 0)');
        foreach (self::DEFAULT_RULES as [$min, $max, $markup]) {
            $this->addSql(
                'INSERT INTO pricing_rule (id, category_id, min_cost_czk_minor, max_cost_czk_minor, markup_bp, active) VALUES (gen_random_uuid(), NULL, :min, :max, :markup, TRUE)',
                ['min' => $min, 'max' => $max, 'markup' => $markup],
            );
        }

        $this->addSql('CREATE TABLE price_change (id UUID NOT NULL, old_price_czk INT DEFAULT NULL, new_price_czk INT NOT NULL, old_price_eur INT DEFAULT NULL, new_price_eur INT NOT NULL, reason VARCHAR(16) NOT NULL, changed_by VARCHAR(180) NOT NULL, changed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, variant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_price_change_variant ON price_change (variant_id, changed_at)');
        $this->addSql('CREATE INDEX IDX_DCE56703B69A9AF ON price_change (variant_id)');
        $this->addSql('ALTER TABLE price_change ADD CONSTRAINT FK_DCE56703B69A9AF FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE price_change ADD CONSTRAINT chk_price_change_reason CHECK (reason IN ('manual', 'reprice', 'import'))");

        // existing orders keep nulls here: what they were sourced from was never recorded
        $this->addSql('ALTER TABLE order_item ADD availability_status VARCHAR(16) DEFAULT NULL, ADD lead_time_min_days SMALLINT DEFAULT NULL, ADD lead_time_max_days SMALLINT DEFAULT NULL, ADD unit_cost_czk_minor INT DEFAULT NULL, ADD supplier_offer_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F09DE4F1C6F FOREIGN KEY (supplier_offer_id) REFERENCES supplier_offer (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_52EA1F09DE4F1C6F ON order_item (supplier_offer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F09DE4F1C6F');
        $this->addSql('DROP INDEX IDX_52EA1F09DE4F1C6F');
        $this->addSql('ALTER TABLE order_item DROP availability_status, DROP lead_time_min_days, DROP lead_time_max_days, DROP unit_cost_czk_minor, DROP supplier_offer_id');

        $this->addSql('DROP TABLE price_change');
        $this->addSql('DROP TABLE pricing_rule');

        $this->addSql('ALTER TABLE product_variant DROP CONSTRAINT chk_variant_market_price');
        $this->addSql('ALTER TABLE product_variant DROP CONSTRAINT chk_variant_rrp');
        $this->addSql('ALTER TABLE product_variant DROP rrp_minor, DROP rrp_currency, DROP rrp_source, DROP rrp_checked_at, DROP market_price_minor, DROP market_price_source, DROP market_checked_at');

        $this->addSql('ALTER TABLE supplier_offer DROP CONSTRAINT chk_supplier_offer_cost');
        $this->addSql('ALTER TABLE supplier_offer DROP inbound_shipping_minor, DROP fx_rate_czk, DROP fx_rate_date');
    }
}
