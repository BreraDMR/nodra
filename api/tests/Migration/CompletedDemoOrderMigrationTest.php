<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260603221749;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260603221749.php';

/**
 * The completed order the D04 migration converted gets its handover time and received lines, and `down` puts it
 * back exactly. Runs inside the test transaction on an order shaped like the migrated demo order.
 */
final class CompletedDemoOrderMigrationTest extends ApiTestCase
{
    public function testMigratedCompletedOrderIsHandedOverWhenPlacedAndDownRestoresIt(): void
    {
        $b = $this->builder();
        $product = $b->product('t-mig-demo', $b->category('t-mig-demo-cat'));
        $variant = $b->variant($product, 'T-MIG-DEMO', stock: 2);
        $demo = $this->orderId($this->checkout([$variant->getId()->toRfc4122() => 1])['reference']);
        $recent = $this->orderId($this->checkout([$variant->getId()->toRfc4122() => 1])['reference']);
        foreach ([$demo => 'completed', $recent => 'processing'] as $id => $previous) {
            // what the D04 migration left behind: one shipment without a handover time, lines to order
            $this->db()->executeStatement("UPDATE shop_order SET status = :status, created_at = '2026-09-20 10:00:00+00' WHERE id = :id", ['status' => $previous === 'completed' ? 'completed' : 'confirmed', 'id' => $id]);
            $this->db()->executeStatement("UPDATE shipment SET status = :status, handed_over_at = NULL WHERE order_id = :id", ['status' => $previous === 'completed' ? 'handed_over' : 'planned', 'id' => $id]);
            $this->db()->executeStatement("UPDATE order_item SET procurement_status = 'to_order' WHERE order_id = :id", ['id' => $id]);
            $this->db()->executeStatement("INSERT INTO order_event (id, order_id, type, data, actor, created_at) VALUES (gen_random_uuid(), :id, 'migrated', jsonb_build_object('previousStatus', CAST(:previous AS TEXT)), 'system', NOW())", ['id' => $id, 'previous' => $previous]);
        }
        $snapshot = fn (): array => [
            $this->db()->fetchAllAssociative('SELECT o.id, s.status, s.handed_over_at FROM shipment s JOIN shop_order o ON o.id = s.order_id WHERE o.id IN (:a, :b) ORDER BY o.id', ['a' => $demo, 'b' => $recent]),
            $this->db()->fetchAllAssociative('SELECT order_id, procurement_status FROM order_item WHERE order_id IN (:a, :b) ORDER BY order_id', ['a' => $demo, 'b' => $recent]),
            (int) $this->db()->fetchOne('SELECT COUNT(*) FROM order_event WHERE order_id IN (:a, :b)', ['a' => $demo, 'b' => $recent]),
        ];
        $before = $snapshot();

        $this->migrate('up');

        self::assertSame(['2026-09-20 10:00:00+00', 'received'], [
            $this->db()->fetchOne('SELECT handed_over_at FROM shipment WHERE order_id = :id', ['id' => $demo]),
            $this->db()->fetchOne('SELECT procurement_status FROM order_item WHERE order_id = :id', ['id' => $demo]),
        ]);
        self::assertSame([null, 'to_order'], [
            $this->db()->fetchOne('SELECT handed_over_at FROM shipment WHERE order_id = :id', ['id' => $recent]),
            $this->db()->fetchOne('SELECT procurement_status FROM order_item WHERE order_id = :id', ['id' => $recent]),
        ], 'only the completed one');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM order_event WHERE order_id = :id AND data ->> 'step' = 'd05-completed-demo'", ['id' => $demo]));
        $this->migrate('up');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM order_event WHERE order_id = :id AND data ->> 'step' = 'd05-completed-demo'", ['id' => $demo]), 'a second run changes nothing');

        $this->migrate('down');
        self::assertSame($before, $snapshot());
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20260603221749($this->db(), new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
