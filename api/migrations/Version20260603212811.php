<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603212811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D05: purchases grouped by supplier with allocated shipping and actual cost, ledger corrections, when an order was confirmed';
    }

    public function up(Schema $schema): void
    {
        // promised dates count from the latest confirmation; orders confirmed by the D04 migration have none
        $this->addSql('ALTER TABLE shop_order ADD confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE shop_order o SET confirmed_at = (SELECT MAX(e.created_at) FROM order_event e WHERE e.order_id = o.id AND e.type = 'confirmed')");

        $this->addSql('CREATE TABLE purchase (id UUID NOT NULL, supplier VARCHAR(32) NOT NULL, seller VARCHAR(120) DEFAULT NULL, reference VARCHAR(120) NOT NULL, currency VARCHAR(3) NOT NULL, fx_rate_czk INT NOT NULL, fx_rate_date DATE DEFAULT NULL, inbound_shipping_minor INT NOT NULL, status VARCHAR(12) NOT NULL, ordered_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, received_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, cancel_reason VARCHAR(500) DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, created_by VARCHAR(180) NOT NULL, idempotency_key VARCHAR(80) NOT NULL, request_hash VARCHAR(64) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6117D13B7FD1C147 ON purchase (idempotency_key)');
        $this->addSql('CREATE INDEX idx_purchase_status ON purchase (status, ordered_at)');
        $this->addSql("ALTER TABLE purchase ADD CONSTRAINT chk_purchase_values CHECK (status IN ('ordered', 'received', 'cancelled')
            AND currency IN ('CZK', 'EUR', 'PLN') AND fx_rate_czk > 0 AND (currency <> 'CZK' OR fx_rate_czk = 1000000)
            AND inbound_shipping_minor >= 0)");

        $this->addSql('CREATE TABLE purchase_line (id UUID NOT NULL, quantity INT NOT NULL, unit_price_minor INT NOT NULL, allocated_shipping_minor INT NOT NULL, unit_cost_czk_minor INT NOT NULL, purchase_id UUID NOT NULL, order_item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_purchase_line_item ON purchase_line (order_item_id)');
        $this->addSql('CREATE INDEX IDX_A1A77C95558FBEB9 ON purchase_line (purchase_id)');
        $this->addSql('ALTER TABLE purchase_line ADD CONSTRAINT FK_A1A77C95558FBEB9 FOREIGN KEY (purchase_id) REFERENCES purchase (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_line ADD CONSTRAINT FK_A1A77C95E415FB15 FOREIGN KEY (order_item_id) REFERENCES order_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_line ADD CONSTRAINT chk_purchase_line_values CHECK (quantity > 0 AND unit_price_minor >= 0 AND allocated_shipping_minor >= 0 AND unit_cost_czk_minor >= 0)');

        // a correction voids one payment or refund, once
        $this->addSql('ALTER TABLE payment ALTER kind TYPE VARCHAR(12)');
        $this->addSql('ALTER TABLE payment ADD corrects_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D94B287F FOREIGN KEY (corrects_id) REFERENCES payment (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D28840D94B287F ON payment (corrects_id)');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT chk_payment_values');
        $this->addSql("ALTER TABLE payment ADD CONSTRAINT chk_payment_values CHECK (kind IN ('payment', 'refund', 'correction')
            AND method IN ('cash', 'bank_transfer', 'card', 'carrier_cod') AND amount_minor > 0
            AND (kind = 'correction') = (corrects_id IS NOT NULL))");

        $this->addSql('ALTER TABLE loyalty_entry DROP CONSTRAINT chk_loyalty_reason');
        $this->addSql("ALTER TABLE loyalty_entry ADD CONSTRAINT chk_loyalty_reason CHECK (reason IN ('earn', 'refund', 'correction'))");
    }

    public function down(Schema $schema): void
    {
        // corrections and purchases don't exist before D05; lines they touched keep their procurement status
        $this->addSql("DELETE FROM loyalty_entry WHERE reason = 'correction'");
        $this->addSql('ALTER TABLE loyalty_entry DROP CONSTRAINT chk_loyalty_reason');
        $this->addSql("ALTER TABLE loyalty_entry ADD CONSTRAINT chk_loyalty_reason CHECK (reason IN ('earn', 'refund'))");

        // orders that had a voided entry get the payment status of the ledger without the corrections
        $this->addSql("UPDATE shop_order o SET payment_status = CASE
                WHEN t.paid <= 0 THEN 'unpaid'
                WHEN t.refunded <= 0 THEN CASE WHEN t.paid >= o.total_minor THEN 'paid' ELSE 'partially_paid' END
                WHEN t.paid - t.refunded <= 0 THEN 'refunded'
                ELSE 'partially_refunded' END
            FROM (SELECT order_id, COALESCE(SUM(amount_minor) FILTER (WHERE kind = 'payment'), 0) AS paid,
                    COALESCE(SUM(amount_minor) FILTER (WHERE kind = 'refund'), 0) AS refunded
                FROM payment GROUP BY order_id) t
            WHERE t.order_id = o.id AND o.id IN (SELECT order_id FROM payment WHERE kind = 'correction')");
        $this->addSql("DELETE FROM payment WHERE kind = 'correction'");
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT chk_payment_values');
        $this->addSql("ALTER TABLE payment ADD CONSTRAINT chk_payment_values CHECK (kind IN ('payment', 'refund')
            AND method IN ('cash', 'bank_transfer', 'card', 'carrier_cod') AND amount_minor > 0)");
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D94B287F');
        $this->addSql('DROP INDEX UNIQ_6D28840D94B287F');
        $this->addSql('ALTER TABLE payment DROP corrects_id');
        $this->addSql('ALTER TABLE payment ALTER kind TYPE VARCHAR(8)');

        $this->addSql('DROP TABLE purchase_line');
        $this->addSql('DROP TABLE purchase');

        $this->addSql('ALTER TABLE shop_order DROP confirmed_at');
    }
}
