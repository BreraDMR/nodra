<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Delivery\PlannedLine;
use App\Pricing\Sourcing;

/** One variant of the basket, repriced in CZK, with where its goods would come from. */
final readonly class BasketLine
{
    /** @param Sourcing $sourcing for this quantity: own stock when it covers it, else the best offer */
    public function __construct(
        public string $variantId,
        public string $name,
        public string $label,
        public string $sku,
        public int $quantity,
        public int $unitPriceMinor,
        public bool $fromStock,
        public Sourcing $sourcing,
    ) {}

    public function lineTotalMinor(): int
    {
        return $this->quantity * $this->unitPriceMinor;
    }

    public function isUnavailable(): bool
    {
        return $this->sourcing->status === Sourcing::UNAVAILABLE;
    }

    public function planned(): PlannedLine
    {
        return new PlannedLine($this->variantId, $this->sourcing->leadTimeMinDays, $this->sourcing->leadTimeMaxDays);
    }
}
