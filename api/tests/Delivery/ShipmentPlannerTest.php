<?php

declare(strict_types=1);

namespace App\Tests\Delivery;

use App\Delivery\DeliveryOption;
use App\Delivery\PlannedLine;
use App\Delivery\PlannedShipment;
use App\Delivery\ShipmentPlanner;
use App\Tests\Support\OrderDefaults;
use PHPUnit\Framework\TestCase;

final class ShipmentPlannerTest extends TestCase
{
    public function testLinesWithTheSameMaximumLeadTimeTravelTogetherAndUnknownOnesLast(): void
    {
        $parts = ShipmentPlanner::parts([
            new PlannedLine('slow', 5, 8),
            new PlannedLine('unknown', null, null),
            new PlannedLine('stock', 1, 1),
            new PlannedLine('also-slow', 7, 8),
            new PlannedLine('half-known', 2, null),
        ]);

        self::assertSame([['stock'], ['slow', 'also-slow'], ['unknown', 'half-known']], array_map(static fn (array $part): array => array_map(static fn (PlannedLine $l): string => $l->key, $part), $parts));
    }

    public function testTogetherIsAsSlowAsTheSlowestLineAndSplitHasOneShipmentPerPart(): void
    {
        $options = OrderDefaults::planner()->options([new PlannedLine('a', 1, 1), new PlannedLine('b', 5, 8), new PlannedLine('c', 7, 8)], 'pickup_andel', 30000);

        self::assertSame([[1, ['a', 'b', 'c'], 7, 8, 0]], self::shipments($options['together']));
        self::assertSame([[1, ['a'], 1, 1, 0], [2, ['b', 'c'], 7, 8, 0]], self::shipments($options['split']));
        self::assertSame(DeliveryOption::SPLIT, $options['split']->fulfilment);
    }

    public function testOneUnknownDateMakesTheWholeShipmentUnknown(): void
    {
        $options = OrderDefaults::planner()->options([new PlannedLine('a', 1, 1), new PlannedLine('b', null, null)], 'pickup_andel', 30000);

        self::assertSame([[1, ['a', 'b'], null, null, 0]], self::shipments($options['together']));
        self::assertSame([[1, ['a'], 1, 1, 0], [2, ['b'], null, null, 0]], self::shipments($options['split']));
    }

    public function testSplitIsNotOfferedWithOnePart(): void
    {
        $options = OrderDefaults::planner()->options([new PlannedLine('a', 3, 6), new PlannedLine('b', 2, 6)], 'prague_personal', 30000);

        self::assertNull($options['split']);
        self::assertSame([[1, ['a', 'b'], 3, 6, 14900]], self::shipments($options['together']));
    }

    public function testEveryPartPaysThePragueFeeBelow500AndNoneFrom500(): void
    {
        $lines = [new PlannedLine('a', 1, 1), new PlannedLine('b', 3, 6)];

        $below = OrderDefaults::planner()->options($lines, 'prague_personal', 49999);
        self::assertSame([14900, 14900], array_map(static fn (PlannedShipment $s): int => $s->feeMinor, $below['split']->shipments));
        self::assertSame([29800, 14900], [$below['split']->shippingMinor(), $below['together']->shippingMinor()]);

        $from = OrderDefaults::planner()->options($lines, 'prague_personal', 50000);
        self::assertSame([0, 0], [$from['split']->shippingMinor(), $from['together']->shippingMinor()]);
    }

    public function testCarrierFeeIsPerPart(): void
    {
        $options = OrderDefaults::planner(carrierFeeMinor: 9900)->options([new PlannedLine('a', 1, 1), new PlannedLine('b', 3, 6)], 'carrier_cz', 90000);

        self::assertSame([19800, 9900], [$options['split']->shippingMinor(), $options['together']->shippingMinor()]);
    }

    /** @return list<array{int, list<string>, ?int, ?int, int}> */
    private static function shipments(DeliveryOption $option): array
    {
        return array_map(static fn (PlannedShipment $s): array => [$s->number, $s->keys, $s->leadTimeMinDays, $s->leadTimeMaxDays, $s->feeMinor], $option->shipments);
    }
}
