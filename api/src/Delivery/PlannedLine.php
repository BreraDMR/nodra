<?php

declare(strict_types=1);

namespace App\Delivery;

/** What shipment planning needs to know about a line. Lead time null = still to be confirmed. */
final readonly class PlannedLine
{
    public function __construct(public string $key, public ?int $leadTimeMinDays, public ?int $leadTimeMaxDays) {}

    public function leadTimeKnown(): bool
    {
        return $this->leadTimeMinDays !== null && $this->leadTimeMaxDays !== null;
    }
}
