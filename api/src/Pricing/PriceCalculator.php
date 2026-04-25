<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Suggested price from landed cost, D02 "Pricing rules":
 * cost + markup rounded up to 10 Kč; above RRP x 0.97 it's capped there (rounded down to 10 Kč);
 * never below cost x 1.10 (rounded up to 10 Kč), and when that floor had to step in it's flagged margin_too_low.
 */
final class PriceCalculator
{
    public function __construct(private PricingSettings $settings) {}

    public function suggest(int $landedCostCzk, int $markupBp, ?int $rrpCzk = null, ?int $marketPriceCzk = null): PriceSuggestion
    {
        $markupPrice = Money::markupUpTo10($landedCostCzk, $markupBp);
        $price = $markupPrice;
        $cap = $rrpCzk === null ? null : Money::shareDownTo10($rrpCzk, $this->settings->rrpFactorBp);
        $capped = $cap !== null && $price > $cap;
        if ($capped) {
            $price = $cap;
        }
        $floor = Money::markupUpTo10($landedCostCzk, $this->settings->minMarginBp);
        $tooLow = $price < $floor;
        if ($tooLow) {
            $price = $floor;
        }

        return new PriceSuggestion(
            $price, $this->eur($price), $markupPrice, $cap, $capped, $floor, $tooLow,
            Money::marginBp($price, $landedCostCzk), $this->aboveMarket($price, $marketPriceCzk),
        );
    }

    /** CZK price shown in EUR until D06.7, rounded to 0.10 € */
    public function eur(int $priceCzk): int
    {
        return Money::czkToEur($priceCzk, $this->settings->eurRate);
    }

    /** RRP in haléře; an EUR RRP goes through the EUR display rate. */
    public function rrpInCzk(?int $rrpMinor, ?string $currency): ?int
    {
        return match (true) {
            $rrpMinor === null => null,
            $currency === 'EUR' => Money::eurToCzk($rrpMinor, $this->settings->eurRate),
            default => $rrpMinor,
        };
    }

    /** More than the threshold above the market price; exactly at the threshold is fine. */
    public function aboveMarket(int $priceCzk, ?int $marketPriceCzk): bool
    {
        return $marketPriceCzk !== null && $priceCzk * 10_000 > $marketPriceCzk * (10_000 + $this->settings->aboveMarketBp);
    }
}
