<?php

declare(strict_types=1);

namespace App\Pricing;

/** Puts sourcing, rule and suggestion together for one variant. No database, so tests can feed it directly. */
final class VariantPricer
{
    public function __construct(private SourcingCalculator $sourcing, private PriceCalculator $prices) {}

    /** @param iterable<OfferFacts> $offers offers of the variant's product */
    public function price(VariantFacts $variant, iterable $offers, RuleBook $rules, \DateTimeImmutable $now): VariantPricing
    {
        $sourcing = $this->sourcing->evaluate($variant->id, $offers, $now);
        $rrpCzk = $this->prices->rrpInCzk($variant->rrpMinor, $variant->rrpCurrency);
        $cost = $sourcing->landedCostCzk;
        $rule = $cost === null ? null : $rules->find($variant->categoryId, $cost);
        $suggestion = $rule === null ? null : $this->prices->suggest($cost, $rule->markupBp, $rrpCzk, $variant->marketPriceMinor);

        return new VariantPricing(
            $variant, $sourcing, $rule, $suggestion, $rrpCzk,
            $cost === null ? null : Money::marginBp($variant->priceCzk, $cost),
            $this->prices->aboveMarket($variant->priceCzk, $variant->marketPriceMinor),
        );
    }
}
