<?php

declare(strict_types=1);

namespace App\Purchase;

use App\Pricing\Money;

/** How a purchase's inbound shipping lands on its lines, and what each unit ends up costing in CZK. Integers only. */
final class Allocation
{
    /**
     * Splits the shipping in proportion to line value (unit price x quantity), rounded down; what rounding leaves
     * goes to the largest line (the first of equals). With no value at all, the first line carries everything.
     *
     * @param list<int> $lineValues purchase currency minor units
     *
     * @return list<int> shipping per line, same order, summing to $shippingMinor
     */
    public static function shipping(int $shippingMinor, array $lineValues): array
    {
        if ($lineValues === []) {
            return [];
        }
        if ($shippingMinor < 0 || min($lineValues) < 0) {
            throw new \InvalidArgumentException('Shipping and line values cannot be negative');
        }
        $total = array_sum($lineValues);
        $shares = array_map(static fn (int $value): int => $total === 0 ? 0 : intdiv($shippingMinor * $value, $total), $lineValues);
        $largest = array_search(max($lineValues), $lineValues, true);
        $shares[$largest] += $shippingMinor - array_sum($shares);

        return $shares;
    }

    /** round((unit price + allocated shipping / quantity) x rate), purchase currency minor units to haléře */
    public static function unitCostCzk(int $unitPriceMinor, int $allocatedShippingMinor, int $quantity, int $fxRateCzk): int
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1');
        }

        return Money::divRound(($unitPriceMinor * $quantity + $allocatedShippingMinor) * $fxRateCzk, $quantity * Money::RATE_ONE);
    }
}
