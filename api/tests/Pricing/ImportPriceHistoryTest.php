<?php

declare(strict_types=1);

namespace App\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportPriceHistoryTest extends KernelTestCase
{
    public function testImportRecordsTheFirstPriceOfEachNewVariant(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);

        $this->import();

        $variants = (int) $db->fetchOne('SELECT COUNT(*) FROM product_variant');
        self::assertGreaterThan(0, $variants);
        self::assertSame(
            [['reason' => 'import', 'changed_by' => 'system', 'rows' => $variants, 'with_old' => 0]],
            array_map(static fn (array $r): array => ['reason' => $r['reason'], 'changed_by' => $r['changed_by'], 'rows' => (int) $r['rows'], 'with_old' => (int) $r['with_old']],
                $db->fetchAllAssociative('SELECT reason, changed_by, COUNT(*) AS rows, COUNT(old_price_czk) AS with_old FROM price_change GROUP BY reason, changed_by')),
        );
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM price_change h JOIN product_variant v ON v.id = h.variant_id
            WHERE h.new_price_czk <> v.price_czk OR h.new_price_eur <> v.price_eur'));

        // a repeated import touches no price, so it writes nothing either
        $this->import();
        self::assertSame($variants, (int) $db->fetchOne('SELECT COUNT(*) FROM price_change'));
    }

    private function import(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        self::assertSame(0, $tester->execute([]));
    }
}
