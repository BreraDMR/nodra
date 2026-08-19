<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260819213717;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260819213717.php';

/**
 * Claims entered before Q15 keep a settle deadline counted from the day the admin recorded them; the migration
 * moves every deadline onto the contact day (+14 return, +30 warranty), the already correct ones included, and
 * down puts the old meaning back. Runs inside the test transaction on rows shaped like the old registry's records.
 */
final class ClaimDueAtMigrationTest extends ApiTestCase
{
    public function testDueAtMovesToTheContactDayAndDownRestoresTheOldMeaning(): void
    {
        $b = $this->builder();
        $product = $b->product('t-mig-due', $b->category('t-mig-due-cat'));
        $variant = $b->variant($product, 'T-MIG-DUE', stock: 2);
        $orderId = $this->orderId($this->checkout([$variant->getId()->toRfc4122() => 1])['reference']);

        // the old records: the deadline from the acceptance day, the contact day backfilled from opened_at (Q15);
        // RC-MIG-CURRENT was entered after Q15, so its deadline is already on the contact day; RC-MIG-LATE was
        // entered four days after the customer called — the only case where down cannot recover the contact day
        $this->db()->executeStatement(
            "INSERT INTO return_claim (id, order_id, number, kind, status, opened_at, opened_by, contacted_on, handover_date, window_end, due_at)
             VALUES
             (gen_random_uuid(), :order, 'RC-MIG-CURRENT', 'return', 'open', '2026-09-02T08:00:00+02:00', 'admin@nodra.test', '2026-09-02', '2026-08-25', '2026-09-08', '2026-09-16'),
             (gen_random_uuid(), :order, 'RC-MIG-LATE', 'return', 'open', '2026-09-10T08:00:00+02:00', 'admin@nodra.test', '2026-09-01', '2026-08-25', '2026-09-08', '2026-09-15'),
             (gen_random_uuid(), :order, 'RC-MIG-RETURN', 'return', 'accepted', '2026-09-01T08:00:00+02:00', 'admin@nodra.test', '2026-09-01', '2026-08-20', '2026-09-03', '2026-09-19'),
             (gen_random_uuid(), :order, 'RC-MIG-WARRANTY', 'warranty', 'open', '2026-03-01T08:00:00+01:00', 'admin@nodra.test', '2026-03-01', '2026-02-20', '2027-02-20', '2026-04-15')",
            ['order' => $orderId],
        );
        $due = fn (): array => array_map('strval', $this->db()->fetchAllKeyValue("SELECT number, due_at FROM return_claim WHERE number LIKE 'RC-MIG-%' ORDER BY number"));
        self::assertSame(['RC-MIG-CURRENT' => '2026-09-16', 'RC-MIG-LATE' => '2026-09-15', 'RC-MIG-RETURN' => '2026-09-19', 'RC-MIG-WARRANTY' => '2026-04-15'], $due());

        $this->migrate('up');
        // every deadline now counts from the contact day: +14 return, +30 warranty, the correct ones unchanged
        self::assertSame(['RC-MIG-CURRENT' => '2026-09-16', 'RC-MIG-LATE' => '2026-09-15', 'RC-MIG-RETURN' => '2026-09-15', 'RC-MIG-WARRANTY' => '2026-03-31'], $due());

        $this->migrate('up');
        self::assertSame(['RC-MIG-CURRENT' => '2026-09-16', 'RC-MIG-LATE' => '2026-09-15', 'RC-MIG-RETURN' => '2026-09-15', 'RC-MIG-WARRANTY' => '2026-03-31'], $due(), 'a second run changes nothing');

        $this->migrate('down');
        // the old rule counted from the admin's day: the old records keep the recomputed deadline, because their
        // contact day is the recorded entry day, while RC-MIG-LATE drifts to its opened day — the documented gap
        self::assertSame(['RC-MIG-CURRENT' => '2026-09-16', 'RC-MIG-LATE' => '2026-09-24', 'RC-MIG-RETURN' => '2026-09-15', 'RC-MIG-WARRANTY' => '2026-03-31'], $due());
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20260819213717($this->db(), new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
