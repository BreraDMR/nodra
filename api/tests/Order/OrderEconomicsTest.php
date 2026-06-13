<?php

declare(strict_types=1);

namespace App\Tests\Order;

use App\Order\OrderEconomics;
use PHPUnit\Framework\TestCase;

final class OrderEconomicsTest extends TestCase
{
    public function testSnapshotCostsGiveTheExpectedResultUntilSomethingIsBought(): void
    {
        $economics = OrderEconomics::of([
            self::line(2, 60000, snapshot: 20000),
            self::line(1, 40000, snapshot: 25000),
        ], 14900);

        self::assertSame([
            'goodsRevenueMinor' => 100000, 'shippingChargedMinor' => 14900, 'costMinor' => 65000, 'expectedResultMinor' => 35000,
            'marginBp' => 5384, 'costComplete' => false, 'unknownCostLines' => 0,
        ], $economics);
    }

    public function testActualCostReplacesTheSnapshotAndCompletesTheCost(): void
    {
        $partly = OrderEconomics::of([self::line(2, 60000, snapshot: 20000, actual: 22000), self::line(1, 40000, snapshot: 25000)], 0);
        self::assertSame([69000, 31000, false], [$partly['costMinor'], $partly['expectedResultMinor'], $partly['costComplete']]);

        $bought = OrderEconomics::of([self::line(2, 60000, snapshot: 20000, actual: 22000), self::line(1, 40000, snapshot: 25000, actual: 24000)], 0);
        self::assertSame([68000, 32000, 4705, true], [$bought['costMinor'], $bought['expectedResultMinor'], $bought['marginBp'], $bought['costComplete']]);
    }

    public function testCancelledLinesAndMissingCostsAreLeftOut(): void
    {
        $economics = OrderEconomics::of([
            self::line(1, 50000, snapshot: 30000, actual: 30000),
            self::line(1, 90000, snapshot: 10000, active: false),
            self::line(1, 20000),
        ], 0);

        self::assertSame([70000, 30000, 40000, false, 1], [$economics['goodsRevenueMinor'], $economics['costMinor'], $economics['expectedResultMinor'], $economics['costComplete'], $economics['unknownCostLines']]);
        self::assertNull(OrderEconomics::of([self::line(1, 20000)], 0)['marginBp']);
        self::assertFalse(OrderEconomics::of([], 0)['costComplete']);
    }

    /** A loss is shown as one. */
    public function testALossIsNegative(): void
    {
        $economics = OrderEconomics::of([self::line(1, 30000, snapshot: 20000, actual: 36000)], 0);

        self::assertSame([-6000, -1667], [$economics['expectedResultMinor'], $economics['marginBp']]);
    }

    private static function line(int $quantity, int $lineTotal, ?int $snapshot = null, ?int $actual = null, bool $active = true): array
    {
        return ['active' => $active, 'quantity' => $quantity, 'lineTotalMinor' => $lineTotal, 'snapshotUnitCostMinor' => $snapshot, 'actualUnitCostMinor' => $actual];
    }
}
