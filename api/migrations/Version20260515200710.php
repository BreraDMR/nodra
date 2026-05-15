<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515200710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D04: order request with customer contact and consents, shipments, payment ledger, order journal, line procurement, loyalty refunds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE shop_order ADD payment_status VARCHAR(20) DEFAULT 'unpaid' NOT NULL, ADD fulfilment VARCHAR(8) DEFAULT 'together' NOT NULL,
            ADD phone VARCHAR(20) DEFAULT NULL, ADD contact_channel VARCHAR(16) DEFAULT NULL, ADD city VARCHAR(120) DEFAULT '' NOT NULL,
            ADD delivery_note VARCHAR(500) DEFAULT NULL, ADD privacy_consented_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            ADD privacy_text_version VARCHAR(40) DEFAULT NULL, ADD marketing_consent BOOLEAN DEFAULT false NOT NULL,
            ADD marketing_consented_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_order_payment_status ON shop_order (payment_status, created_at)');

        $this->addSql('CREATE TABLE shipment (id UUID NOT NULL, position SMALLINT NOT NULL, method VARCHAR(20) NOT NULL, fee_minor INT NOT NULL, status VARCHAR(12) NOT NULL, scheduled_from TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, scheduled_to TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, handed_over_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_shipment_order ON shipment (order_id, position)');
        $this->addSql('CREATE INDEX IDX_2CB20DC8D9F6D38 ON shipment (order_id)');
        $this->addSql('ALTER TABLE shipment ADD CONSTRAINT FK_2CB20DC8D9F6D38 FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE shipment ADD CONSTRAINT chk_shipment_values CHECK (method IN ('pickup_andel', 'prague_personal', 'carrier_cz')
            AND status IN ('planned', 'scheduled', 'handed_over', 'refused', 'cancelled') AND fee_minor >= 0
            AND (scheduled_from IS NULL OR scheduled_to IS NULL OR scheduled_to > scheduled_from))");

        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, kind VARCHAR(8) NOT NULL, method VARCHAR(16) NOT NULL, amount_minor INT NOT NULL, recorded_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, recorded_by VARCHAR(180) NOT NULL, note VARCHAR(500) DEFAULT NULL, idempotency_key VARCHAR(80) NOT NULL, request_hash VARCHAR(64) NOT NULL, order_id UUID NOT NULL, shipment_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D28840D7FD1C147 ON payment (idempotency_key)');
        $this->addSql('CREATE INDEX idx_payment_order ON payment (order_id, recorded_at)');
        $this->addSql('CREATE INDEX IDX_6D28840D8D9F6D38 ON payment (order_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D7BE036FC ON payment (shipment_id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D7BE036FC FOREIGN KEY (shipment_id) REFERENCES shipment (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql("ALTER TABLE payment ADD CONSTRAINT chk_payment_values CHECK (kind IN ('payment', 'refund')
            AND method IN ('cash', 'bank_transfer', 'card', 'carrier_cod') AND amount_minor > 0)");

        $this->addSql('CREATE TABLE order_event (id UUID NOT NULL, type VARCHAR(40) NOT NULL, data JSONB NOT NULL, actor VARCHAR(180) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_order_event_order ON order_event (order_id, created_at)');
        $this->addSql('CREATE INDEX IDX_B8307E5A8D9F6D38 ON order_event (order_id)');
        $this->addSql('ALTER TABLE order_event ADD CONSTRAINT FK_B8307E5A8D9F6D38 FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE CASCADE NOT DEFERRABLE');

        // every existing order gets one shipment with its old shipping amount; the method follows the postal code
        $this->addSql("INSERT INTO shipment (id, order_id, position, method, fee_minor, status, created_at)
            SELECT gen_random_uuid(), id, 1,
                CASE WHEN regexp_replace(postal_code, '[[:space:]]', '', 'g') ~ '^1[0-9]{4}$' THEN 'prague_personal' ELSE 'carrier_cz' END,
                shipping_minor,
                CASE status WHEN 'shipped' THEN 'scheduled' WHEN 'completed' THEN 'handed_over' WHEN 'cancelled' THEN 'cancelled' ELSE 'planned' END,
                created_at
            FROM shop_order");

        $this->addSql("ALTER TABLE order_item ADD procurement_status VARCHAR(12) DEFAULT 'to_order' NOT NULL, ADD state VARCHAR(10) DEFAULT 'active' NOT NULL,
            ADD supplier_reference VARCHAR(120) DEFAULT NULL, ADD shipment_id UUID DEFAULT NULL, ADD replaces_item_id UUID DEFAULT NULL");
        $this->addSql('UPDATE order_item i SET shipment_id = s.id FROM shipment s WHERE s.order_id = i.order_id');
        $this->addSql("UPDATE order_item i SET state = 'cancelled' FROM shop_order o WHERE o.id = i.order_id AND o.status = 'cancelled'");
        $this->addSql('ALTER TABLE order_item ALTER procurement_status DROP DEFAULT, ALTER state DROP DEFAULT, ALTER shipment_id SET NOT NULL');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F097BE036FC FOREIGN KEY (shipment_id) REFERENCES shipment (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F093CC67A97 FOREIGN KEY (replaces_item_id) REFERENCES order_item (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_52EA1F097BE036FC ON order_item (shipment_id)');
        $this->addSql('CREATE INDEX IDX_52EA1F093CC67A97 ON order_item (replaces_item_id)');
        $this->addSql("ALTER TABLE order_item ADD CONSTRAINT chk_order_item_state CHECK (procurement_status IN ('from_stock', 'to_order', 'ordered', 'received', 'failed')
            AND state IN ('active', 'cancelled', 'returned'))");

        // a completed demo order was paid, so its status has a source in the ledger
        $this->addSql("INSERT INTO payment (id, order_id, shipment_id, kind, method, amount_minor, recorded_at, recorded_by, note, idempotency_key, request_hash)
            SELECT gen_random_uuid(), o.id, s.id, 'payment', 'cash', o.total_minor, o.created_at, 'system', 'pre-D04 demo order', 'pre-d04-' || o.id, repeat('0', 64)
            FROM shop_order o JOIN shipment s ON s.order_id = o.id WHERE o.status = 'completed' AND o.total_minor > 0");
        $this->addSql("UPDATE shop_order o SET payment_status = 'paid' WHERE EXISTS (SELECT 1 FROM payment p WHERE p.order_id = o.id)");
        $this->addSql("INSERT INTO order_event (id, order_id, type, data, actor, created_at)
            SELECT gen_random_uuid(), id, 'migrated', jsonb_build_object('previousStatus', status, 'note', 'Placed before D04; converted with one shipment and lines to order'), 'system', NOW()
            FROM shop_order");

        $this->addSql("UPDATE shop_order SET status = CASE status WHEN 'placed' THEN 'requested' WHEN 'processing' THEN 'confirmed' WHEN 'shipped' THEN 'confirmed' ELSE status END");
        $this->addSql("ALTER TABLE shop_order ADD CONSTRAINT chk_order_states CHECK (status IN ('requested', 'confirmed', 'completed', 'cancelled')
            AND payment_status IN ('unpaid', 'partially_paid', 'paid', 'partially_refunded', 'refunded') AND fulfilment IN ('together', 'split')
            AND (contact_channel IS NULL OR contact_channel IN ('whatsapp', 'telegram', 'phone')))");

        // one earn entry per order stays unique, refunds add negative entries next to it
        $this->addSql('DROP INDEX uniq_loyalty_order');
        $this->addSql("ALTER TABLE loyalty_entry ADD reason VARCHAR(10) DEFAULT 'earn' NOT NULL");
        $this->addSql("ALTER TABLE loyalty_entry ADD CONSTRAINT chk_loyalty_reason CHECK (reason IN ('earn', 'refund'))");
        $this->addSql('CREATE INDEX idx_loyalty_order ON loyalty_entry (shop_order_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_loyalty_earn ON loyalty_entry (shop_order_id) WHERE (reason = 'earn')");

        $this->addSql('ALTER TABLE stock_movement ADD order_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B58D9F6D38 FOREIGN KEY (order_id) REFERENCES shop_order (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BB1BC1B58D9F6D38 ON stock_movement (order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B58D9F6D38');
        $this->addSql('DROP INDEX IDX_BB1BC1B58D9F6D38');
        $this->addSql('ALTER TABLE stock_movement DROP order_id');

        $this->addSql("DELETE FROM loyalty_entry WHERE reason <> 'earn'");
        $this->addSql('DROP INDEX uniq_loyalty_earn');
        $this->addSql('DROP INDEX idx_loyalty_order');
        $this->addSql('ALTER TABLE loyalty_entry DROP CONSTRAINT chk_loyalty_reason');
        $this->addSql('ALTER TABLE loyalty_entry DROP reason');
        $this->addSql('CREATE UNIQUE INDEX uniq_loyalty_order ON loyalty_entry (shop_order_id)');

        // back to the old statuses; a confirmed order with a scheduled or delivered shipment was "shipped"
        $this->addSql('ALTER TABLE shop_order DROP CONSTRAINT chk_order_states');
        $this->addSql("UPDATE shop_order o SET status = CASE
                WHEN o.status = 'requested' THEN 'placed'
                WHEN o.status = 'confirmed' AND EXISTS (SELECT 1 FROM shipment s WHERE s.order_id = o.id AND s.status IN ('scheduled', 'handed_over')) THEN 'shipped'
                WHEN o.status = 'confirmed' THEN 'processing'
                ELSE o.status END");

        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT chk_order_item_state');
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F097BE036FC');
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F093CC67A97');
        $this->addSql('DROP INDEX IDX_52EA1F097BE036FC');
        $this->addSql('DROP INDEX IDX_52EA1F093CC67A97');
        $this->addSql('ALTER TABLE order_item DROP procurement_status, DROP state, DROP supplier_reference, DROP shipment_id, DROP replaces_item_id');

        $this->addSql('DROP TABLE order_event');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE shipment');

        $this->addSql('DROP INDEX idx_order_payment_status');
        $this->addSql('ALTER TABLE shop_order DROP payment_status, DROP fulfilment, DROP phone, DROP contact_channel, DROP city, DROP delivery_note,
            DROP privacy_consented_at, DROP privacy_text_version, DROP marketing_consent, DROP marketing_consented_at');
    }
}
