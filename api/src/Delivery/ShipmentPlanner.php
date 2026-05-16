<?php

declare(strict_types=1);

namespace App\Delivery;

/**
 * Together or in parts. Lines with the same maximum lead time travel together; lines whose date is still to be
 * confirmed form one "we'll confirm" part, last. `together` is one shipment as slow as the slowest line; `split`
 * is one shipment per part and exists only with at least two parts. Every shipment pays the method's fee.
 */
final class ShipmentPlanner
{
    public function __construct(private DeliveryRules $rules) {}

    /**
     * @param non-empty-list<PlannedLine> $lines
     *
     * @return array{together: DeliveryOption, split: ?DeliveryOption}
     */
    public function options(array $lines, string $method, int $goodsSubtotalMinor): array
    {
        $fee = $this->rules->fee($method, $goodsSubtotalMinor);
        $parts = self::parts($lines);
        $shipments = [];
        foreach ($parts as $index => $part) {
            $shipments[] = self::shipment($index + 1, $part, $fee);
        }

        return [
            'together' => new DeliveryOption(DeliveryOption::TOGETHER, [self::shipment(1, $lines, $fee)]),
            'split' => count($parts) < 2 ? null : new DeliveryOption(DeliveryOption::SPLIT, $shipments),
        ];
    }

    /**
     * @param list<PlannedLine> $lines
     *
     * @return list<non-empty-list<PlannedLine>> quickest first, the unknown part last
     */
    public static function parts(array $lines): array
    {
        $known = [];
        $unknown = [];
        foreach ($lines as $line) {
            if ($line->leadTimeKnown()) {
                $known[$line->leadTimeMaxDays][] = $line;
            } else {
                $unknown[] = $line;
            }
        }
        ksort($known);
        $parts = array_values($known);
        if ($unknown !== []) {
            $parts[] = $unknown;
        }

        return $parts;
    }

    /** @param non-empty-list<PlannedLine> $lines */
    private static function shipment(int $number, array $lines, int $fee): PlannedShipment
    {
        $known = array_filter($lines, static fn (PlannedLine $line): bool => $line->leadTimeKnown());
        // it leaves when its slowest line is there; one unknown date makes the whole shipment unknown
        $allKnown = count($known) === count($lines);

        return new PlannedShipment(
            $number,
            array_map(static fn (PlannedLine $line): string => $line->key, $lines),
            $allKnown ? max(array_map(static fn (PlannedLine $line): int => $line->leadTimeMinDays, $lines)) : null,
            $allKnown ? max(array_map(static fn (PlannedLine $line): int => $line->leadTimeMaxDays, $lines)) : null,
            $fee,
        );
    }
}
