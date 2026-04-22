<?php

declare(strict_types=1);

namespace App\Pricing;

/** A supplier offer as pricing and availability read it. */
final readonly class OfferFacts
{
    public function __construct(
        public string $id,
        public string $productId,
        public ?string $variantId,
        public string $verificationStatus,
        public string $currency,
        public int $priceMinor,
        public \DateTimeImmutable $checkedAt,
        public int $inboundShippingMinor = 0,
        public ?int $fxRateCzk = null,
        public ?\DateTimeImmutable $fxRateDate = null,
        public ?int $reportedQuantity = null,
        public ?int $leadTimeMinDays = null,
        public ?int $leadTimeMaxDays = null,
        public string $supplier = 'other',
        public ?string $seller = null,
        public string $url = '',
    ) {}

    public static function fromRow(array $row): self
    {
        $int = static fn (mixed $value): ?int => $value === null ? null : (int) $value;

        return new self(
            $row['id'], $row['product_id'], $row['variant_id'], $row['verification_status'], $row['currency'],
            (int) $row['price_minor'], new \DateTimeImmutable($row['checked_at']), (int) $row['inbound_shipping_minor'],
            $int($row['fx_rate_czk']), $row['fx_rate_date'] === null ? null : new \DateTimeImmutable($row['fx_rate_date']),
            $int($row['reported_quantity']), $int($row['lead_time_min_days']), $int($row['lead_time_max_days']),
            $row['supplier'], $row['seller'], $row['url'],
        );
    }

    /** Haléře, or null while the exchange rate is unknown. */
    public function landedCostCzk(): ?int
    {
        return $this->fxRateCzk === null ? null : Money::landedCost($this->priceMinor, $this->inboundShippingMinor, $this->fxRateCzk);
    }

    /** Reported quantity 0 means sold out; null means the supplier didn't say. */
    public function hasStock(): bool
    {
        return $this->reportedQuantity === null || $this->reportedQuantity > 0;
    }

    public function leadTimeKnown(): bool
    {
        return $this->leadTimeMinDays !== null && $this->leadTimeMaxDays !== null;
    }
}
