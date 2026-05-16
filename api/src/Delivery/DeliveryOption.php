<?php

declare(strict_types=1);

namespace App\Delivery;

final readonly class DeliveryOption
{
    public const TOGETHER = 'together';
    public const SPLIT = 'split';

    /** @param list<PlannedShipment> $shipments */
    public function __construct(public string $fulfilment, public array $shipments) {}

    public function shippingMinor(): int
    {
        return array_sum(array_map(static fn (PlannedShipment $s): int => $s->feeMinor, $this->shipments));
    }
}
