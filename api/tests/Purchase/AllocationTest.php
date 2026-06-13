<?php

declare(strict_types=1);

namespace App\Tests\Purchase;

use App\Purchase\Allocation;
use PHPUnit\Framework\TestCase;

final class AllocationTest extends TestCase
{
    public function testShippingFollowsLineValueAndTheRemainderGoesToTheLargestLine(): void
    {
        // values 1000, 3000, 2000 of 6000; 1000 shipping gives 166.67, 500, 333.33 -> 166, 500, 333 and 1 left over
        self::assertSame([166, 501, 333], Allocation::shipping(1000, [1000, 3000, 2000]));
        // three equal lines: the remainder goes to the first of the largest
        self::assertSame([34, 33, 33], Allocation::shipping(100, [500, 500, 500]));
        self::assertSame([0, 0, 7], Allocation::shipping(7, [1, 1, 998]));
    }

    public function testEverySplitAddsUpToTheShipping(): void
    {
        foreach ([[999, [1, 2, 3]], [1, [700, 300]], [12345, [1999, 1, 50, 7777]], [0, [100, 200]]] as [$shipping, $values]) {
            $shares = Allocation::shipping($shipping, $values);
            self::assertSame($shipping, array_sum($shares));
            self::assertCount(count($values), $shares);
            self::assertGreaterThanOrEqual(0, min($shares));
        }
    }

    public function testNoValueAtAllPutsTheShippingOnTheFirstLine(): void
    {
        self::assertSame([500, 0], Allocation::shipping(500, [0, 0]));
        self::assertSame([], Allocation::shipping(500, []));
    }

    public function testActualUnitCostOfAnEurPurchase(): void
    {
        // 20.00 € a unit, 3 units, 4.50 € of shipping on the line, 25.315 CZK per €: (2000 + 450/3) x 25.315 = 544.2725 Kč
        self::assertSame(54427, Allocation::unitCostCzk(2000, 450, 3, 25_315_000));
        // half a haléř rounds up
        self::assertSame(3, Allocation::unitCostCzk(1, 0, 2, 2_500_000));
        // a CZK purchase costs what it says
        self::assertSame(49900, Allocation::unitCostCzk(49900, 0, 1, 1_000_000));
        self::assertSame(50150, Allocation::unitCostCzk(49900, 500, 2, 1_000_000));
    }

    public function testNegativeValuesAndNoQuantityAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Allocation::unitCostCzk(100, 0, 0, 1_000_000);
    }
}
