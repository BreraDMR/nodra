<?php

declare(strict_types=1);

namespace App\Pricing;

final readonly class VariantPricing
{
    public const MARGIN_TOO_LOW = 'margin_too_low';
    public const ABOVE_MARKET = 'above_market';

    public function __construct(
        public VariantFacts $variant,
        public Sourcing $sourcing,
        public ?RuleFacts $rule,
        public ?PriceSuggestion $suggestion,
        public ?int $rrpCzk,
        /** the current CZK price over landed cost */
        public ?int $currentMarginBp,
        /** the current CZK price is above the market threshold */
        public bool $aboveMarket,
    ) {}

    /** @return list<string> */
    public function flags(): array
    {
        $flags = [];
        if ($this->suggestion?->marginTooLow) {
            $flags[] = self::MARGIN_TOO_LOW;
        }
        if ($this->aboveMarket) {
            $flags[] = self::ABOVE_MARKET;
        }

        return $flags;
    }

    /** Only a suggestion that keeps the rule/RRP target within the minimum margin may be applied. */
    public function applicable(): bool
    {
        return $this->suggestion !== null && !$this->suggestion->marginTooLow;
    }

    public function changes(): bool
    {
        return $this->suggestion !== null
            && ($this->suggestion->priceCzk !== $this->variant->priceCzk || $this->suggestion->priceEur !== $this->variant->priceEur);
    }
}
