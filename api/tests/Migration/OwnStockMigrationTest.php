<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260515201451;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260515201451.php';

/** The demo stock reset runs inside the test transaction: up clears it with a movement per variant, down puts it back. */
final class OwnStockMigrationTest extends ApiTestCase
{
    public function testDemoStockIsClearedAndRestored(): void
    {
        $b = $this->builder();
        $product = $b->product('t-migrate', $b->category('t-migrate-cat'));
        $b->variant($product, 'T-MIG-7', stock: 7);
        $b->variant($product, 'T-MIG-1', stock: 1);
        $b->variant($product, 'T-MIG-0', stock: 0);
        $stock = fn (): array => array_map('intval', $this->db()->fetchAllKeyValue("SELECT sku, stock FROM product_variant WHERE sku LIKE 'T-MIG-%' ORDER BY sku"));
        $movements = fn (): array => array_map('intval', $this->db()->fetchAllKeyValue("SELECT v.sku, m.delta FROM stock_movement m JOIN product_variant v ON v.id = m.variant_id
            WHERE v.sku LIKE 'T-MIG-%' AND m.reason = 'Demo supplier snapshot cleared (D04)' ORDER BY v.sku"));
        $before = $stock();

        $this->migrate('up');
        self::assertSame(['T-MIG-0' => 0, 'T-MIG-1' => 0, 'T-MIG-7' => 0], $stock());
        self::assertSame(['T-MIG-1' => -1, 'T-MIG-7' => -7], $movements());

        $this->migrate('down');
        self::assertSame($before, $stock());
        self::assertSame([], $movements());
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20260515201451($this->db(), new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
