<?php

declare(strict_types=1);

namespace App\Delivery;

final readonly class PlannedShipment
{
    /** @param list<string> $keys the lines it carries */
    public function __construct(
        public int $number,
        public array $keys,
        public ?int $leadTimeMinDays,
        public ?int $leadTimeMaxDays,
        public int $feeMinor,
    ) {}
}
