<?php

declare(strict_types=1);

namespace App\Pricing;

/** A variant's price inputs, loaded in bulk. */
final readonly class VariantFacts
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $categoryId,
        public int $priceCzk,
        public int $priceEur,
        public ?int $rrpMinor = null,
        public ?string $rrpCurrency = null,
        public ?int $marketPriceMinor = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            $row['id'], $row['product_id'], $row['category_id'], (int) $row['price_czk'], (int) $row['price_eur'],
            $row['rrp_minor'] === null ? null : (int) $row['rrp_minor'], $row['rrp_currency'],
            $row['market_price_minor'] === null ? null : (int) $row['market_price_minor'],
        );
    }
}
