<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603221749 extends AbstractMigration
{
    /** orders the D04 migration converted from the old `completed` status */
    private const MIGRATED_COMPLETED = "EXISTS (SELECT 1 FROM order_event m WHERE m.order_id = o.id AND m.type = 'migrated' AND m.data ->> 'previousStatus' = 'completed')";

    public function getDescription(): string
    {
        return 'D05: the completed pre-D04 demo order was handed over when it was placed and its lines were received';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO order_event (id, order_id, type, data, actor, created_at)
            SELECT gen_random_uuid(), o.id, 'migrated', jsonb_build_object('step', 'd05-completed-demo', 'note', 'Handover time set to the order date, lines marked received'), 'system', NOW()
            FROM shop_order o
            WHERE o.status = 'completed' AND ".self::MIGRATED_COMPLETED."
                AND EXISTS (SELECT 1 FROM shipment s WHERE s.order_id = o.id AND s.status = 'handed_over' AND s.handed_over_at IS NULL)");
        $this->addSql("UPDATE shipment s SET handed_over_at = o.created_at FROM shop_order o
            WHERE s.order_id = o.id AND o.status = 'completed' AND s.status = 'handed_over' AND s.handed_over_at IS NULL AND ".self::MIGRATED_COMPLETED);
        $this->addSql("UPDATE order_item i SET procurement_status = 'received' FROM shop_order o
            WHERE i.order_id = o.id AND o.status = 'completed' AND i.state = 'active' AND i.procurement_status = 'to_order' AND ".self::MIGRATED_COMPLETED);
    }

    public function down(Schema $schema): void
    {
        // the D04 migration left every line of these orders to_order and no handover time
        $this->addSql("UPDATE order_item i SET procurement_status = 'to_order' FROM shop_order o
            WHERE i.order_id = o.id AND i.procurement_status = 'received' AND ".self::MIGRATED_COMPLETED);
        $this->addSql('UPDATE shipment s SET handed_over_at = NULL FROM shop_order o
            WHERE s.order_id = o.id AND s.handed_over_at = o.created_at AND '.self::MIGRATED_COMPLETED);
        $this->addSql("DELETE FROM order_event WHERE type = 'migrated' AND data ->> 'step' = 'd05-completed-demo'");
    }
}
