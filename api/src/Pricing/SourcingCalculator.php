<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Availability and best offer of one variant, from the offers of its product.
 *
 * - unavailable: no offers, or every one is rejected or reports quantity 0;
 * - orderable: the best fresh matched offer (lowest landed cost, then shorter max lead time) has a known lead time;
 * - check_needed: anything else, i.e. only stale, snapshot or unmatched offers, no exchange rate, unknown lead time.
 */
final class SourcingCalculator
{
    public function __construct(private PricingSettings $settings) {}

    /** @param iterable<OfferFacts> $offers offers of the variant's product, rows of its other variants are skipped */
    public function evaluate(string $variantId, iterable $offers, \DateTimeImmutable $now): Sourcing
    {
        $freshSince = $now->getTimestamp() - $this->settings->freshOfferDays * 86_400;
        $listed = false;
        $usable = [];
        $candidates = [];
        foreach ($offers as $offer) {
            if ($offer->variantId !== null && $offer->variantId !== $variantId) {
                continue;
            }
            if ($offer->verificationStatus === 'rejected') {
                continue;
            }
            $listed = true;
            if (!$offer->hasStock()) {
                continue;
            }
            $usable[] = $offer;
            if ($offer->verificationStatus === 'matched' && $offer->checkedAt->getTimestamp() >= $freshSince && $offer->fxRateCzk !== null) {
                $candidates[] = $offer;
            }
        }
        if ($usable === []) {
            return Sourcing::unavailable($listed ? 'sold_out' : 'no_offers');
        }
        if ($candidates === []) {
            return new Sourcing(Sourcing::CHECK_NEEDED, $this->whyNotMatched($usable, $freshSince));
        }

        usort($candidates, static fn (OfferFacts $a, OfferFacts $b): int => [$a->landedCostCzk(), $a->leadTimeMaxDays ?? PHP_INT_MAX, $a->leadTimeMinDays ?? PHP_INT_MAX, $a->id]
            <=> [$b->landedCostCzk(), $b->leadTimeMaxDays ?? PHP_INT_MAX, $b->leadTimeMinDays ?? PHP_INT_MAX, $b->id]);
        $best = $candidates[0];
        if (!$best->leadTimeKnown()) {
            return new Sourcing(Sourcing::CHECK_NEEDED, 'unknown_lead_time', $best, $best->landedCostCzk());
        }

        return new Sourcing(
            Sourcing::ORDERABLE, null, $best, $best->landedCostCzk(),
            $best->leadTimeMinDays + $this->settings->handlingDays, $best->leadTimeMaxDays + $this->settings->handlingDays,
        );
    }

    /** @param non-empty-list<OfferFacts> $usable */
    private function whyNotMatched(array $usable, int $freshSince): string
    {
        $matched = array_filter($usable, static fn (OfferFacts $offer): bool => $offer->verificationStatus === 'matched');
        foreach ($matched as $offer) {
            if ($offer->checkedAt->getTimestamp() >= $freshSince) {
                return 'missing_fx';
            }
        }

        return $matched === [] ? 'not_matched' : 'stale';
    }
}
