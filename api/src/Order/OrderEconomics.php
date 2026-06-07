<?php

declare(strict_types=1);

namespace App\Order;

use App\Pricing\Money;

/**
 * What an order is expected to earn: goods revenue of the active lines minus their cost, the actual cost from a
 * purchase where there is one and the checkout snapshot otherwise. Shipping charged is shown next to it, not in it.
 * Admin-only. All amounts are haléře: economics exist for CZK orders only.
 */
final class OrderEconomics
{
    /**
     * @param list<array{active: bool, quantity: int, lineTotalMinor: int, snapshotUnitCostMinor: ?int, actualUnitCostMinor: ?int}> $lines
     *
     * @return array{goodsRevenueMinor: int, shippingChargedMinor: int, costMinor: int, expectedResultMinor: int, marginBp: ?int, costComplete: bool, unknownCostLines: int}
     */
    public static function of(array $lines, int $shippingChargedMinor): array
    {
        $revenue = 0;
        $cost = 0;
        $active = 0;
        $actual = 0;
        $unknown = 0;
        foreach ($lines as $line) {
            if (!$line['active']) {
                continue;
            }
            ++$active;
            $revenue += $line['lineTotalMinor'];
            $unitCost = $line['actualUnitCostMinor'] ?? $line['snapshotUnitCostMinor'];
            if ($line['actualUnitCostMinor'] !== null) {
                ++$actual;
            }
            if ($unitCost === null) {
                // own stock without an offer, or a line older than D02: no cost to count
                ++$unknown;
                continue;
            }
            $cost += $unitCost * $line['quantity'];
        }

        return [
            'goodsRevenueMinor' => $revenue,
            'shippingChargedMinor' => $shippingChargedMinor,
            'costMinor' => $cost,
            'expectedResultMinor' => $revenue - $cost,
            // over cost, the way the pricing panel shows margins; null without a cost
            'marginBp' => Money::marginBp($revenue, $cost),
            'costComplete' => $active > 0 && $actual === $active,
            'unknownCostLines' => $unknown,
        ];
    }
}
