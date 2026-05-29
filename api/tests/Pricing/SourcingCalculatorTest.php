<?php

declare(strict_types=1);

namespace App\Tests\Pricing;

use App\Pricing\OfferFacts;
use App\Pricing\Sourcing;
use App\Pricing\SourcingCalculator;
use App\Tests\Support\PricingDefaults;
use PHPUnit\Framework\TestCase;

final class SourcingCalculatorTest extends TestCase
{
    private SourcingCalculator $calculator;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->calculator = new SourcingCalculator(PricingDefaults::settings());
        $this->now = new \DateTimeImmutable('2026-09-28T12:00:00+00:00');
    }

    public function testFreshMatchedOfferIsOrderableWithHandlingDay(): void
    {
        $s = $this->evaluate($this->offer('a', leadMin: 2, leadMax: 5));

        self::assertSame([Sourcing::ORDERABLE, null, 'a', 120000, 3, 6], [$s->status, $s->reason, $s->offer?->id, $s->landedCostCzk, $s->leadTimeMinDays, $s->leadTimeMaxDays]);
        self::assertSame(['status' => 'orderable', 'leadTimeMinDays' => 3, 'leadTimeMaxDays' => 6], $s->toPublic());
    }

    public function testFreshnessBoundaryIsSevenDays(): void
    {
        $exactly = $this->evaluate($this->offer('a', checkedAt: $this->now->modify('-7 days')));
        $later = $this->evaluate($this->offer('a', checkedAt: $this->now->modify('-7 days -1 second')));

        self::assertSame(Sourcing::ORDERABLE, $exactly->status);
        self::assertSame([Sourcing::CHECK_NEEDED, 'stale', null], [$later->status, $later->reason, $later->offer]);
        self::assertSame(['status' => 'check_needed', 'leadTimeMinDays' => null, 'leadTimeMaxDays' => null], $later->toPublic());
    }

    public function testUnknownLeadTimeNeedsACheckButStillGivesTheCost(): void
    {
        $s = $this->evaluate($this->offer('a', leadMin: 2, leadMax: null));

        self::assertSame([Sourcing::CHECK_NEEDED, 'unknown_lead_time', 'a', 120000, null, null], [$s->status, $s->reason, $s->offer?->id, $s->landedCostCzk, $s->leadTimeMinDays, $s->leadTimeMaxDays]);
    }

    public function testUnavailableWithoutOffersOrWhenAllAreRejectedOrSoldOut(): void
    {
        self::assertSame([Sourcing::UNAVAILABLE, 'no_offers'], $this->statusOf());
        self::assertSame([Sourcing::UNAVAILABLE, 'no_offers'], $this->statusOf($this->offer('a', status: 'rejected')));
        self::assertSame([Sourcing::UNAVAILABLE, 'sold_out'], $this->statusOf($this->offer('a', quantity: 0), $this->offer('b', status: 'rejected')));
        // another variant's offer says nothing about this one
        self::assertSame([Sourcing::UNAVAILABLE, 'no_offers'], $this->statusOf($this->offer('a', variantId: 'v2')));
    }

    public function testOnlySnapshotStaleOrUnpricedOffersNeedACheck(): void
    {
        self::assertSame([Sourcing::CHECK_NEEDED, 'not_matched'], $this->statusOf($this->offer('a', variantId: null, status: 'snapshot')));
        // the fresh one is sold out, the one left is stale
        self::assertSame([Sourcing::CHECK_NEEDED, 'stale'], $this->statusOf($this->offer('a', quantity: 0), $this->offer('b', checkedAt: $this->now->modify('-30 days'))));
        self::assertSame([Sourcing::CHECK_NEEDED, 'missing_fx'], $this->statusOf($this->offer('a', currency: 'EUR', price: 4500, fx: null)));
        // unknown supplier quantity doesn't block
        self::assertSame([Sourcing::ORDERABLE, null], $this->statusOf($this->offer('a', quantity: null)));
    }

    public function testBestOfferIsTheLowestLandedCostThenTheShorterLeadTime(): void
    {
        // 44 € + 3 € shipping at 25.315 = 1189.805 Kč beats 1200 Kč
        $s = $this->evaluate(
            $this->offer('czk', price: 120000),
            $this->offer('eur', currency: 'EUR', price: 4400, fx: 25_315_000, shipping: 300, leadMin: 4, leadMax: 8),
        );
        self::assertSame(['eur', 118981, 5, 9], [$s->offer?->id, $s->landedCostCzk, $s->leadTimeMinDays, $s->leadTimeMaxDays]);

        $tie = $this->evaluate($this->offer('slow', leadMin: 1, leadMax: 9), $this->offer('quick', leadMin: 3, leadMax: 4));
        self::assertSame(['quick', 4, 5], [$tie->offer?->id, $tie->leadTimeMinDays, $tie->leadTimeMaxDays]);
    }

    public function testOwnStockIsOrderableInTheHandlingDaysAndKeepsTheOfferForTheCost(): void
    {
        $stale = $this->evaluate($this->offer('a', checkedAt: $this->now->modify('-30 days')));
        $held = $this->calculator->withOwnStock($stale, 2);
        self::assertSame(['status' => 'orderable', 'leadTimeMinDays' => 1, 'leadTimeMaxDays' => 1], $held->toPublic());

        $offered = $this->evaluate($this->offer('a', leadMin: 2, leadMax: 5));
        $covered = $this->calculator->withOwnStock($offered, 2, 2);
        self::assertSame(['a', 120000, 1, 1], [$covered->offer?->id, $covered->landedCostCzk, $covered->leadTimeMinDays, $covered->leadTimeMaxDays]);

        // stock that doesn't cover the quantity changes nothing, the line is bought from the supplier
        self::assertSame($offered, $this->calculator->withOwnStock($offered, 2, 3));
        self::assertSame(Sourcing::UNAVAILABLE, $this->calculator->withOwnStock($this->evaluate(), 0)->status);
        self::assertSame(Sourcing::ORDERABLE, $this->calculator->withOwnStock($this->evaluate(), 1)->status);
    }

    public function testCardShowsTheBestVariant(): void
    {
        $best = Sourcing::best([
            new Sourcing(Sourcing::CHECK_NEEDED, 'stale'),
            new Sourcing(Sourcing::ORDERABLE, null, null, 1, 4, 7),
            new Sourcing(Sourcing::ORDERABLE, null, null, 1, 2, 4),
            Sourcing::unavailable(),
        ]);

        self::assertSame(['status' => 'orderable', 'leadTimeMinDays' => 2, 'leadTimeMaxDays' => 4], $best->toPublic());
        self::assertSame('check_needed', Sourcing::best([Sourcing::unavailable(), new Sourcing(Sourcing::CHECK_NEEDED, 'stale')])->status);
        self::assertSame('unavailable', Sourcing::best([])->status);
    }

    private function evaluate(OfferFacts ...$offers): Sourcing
    {
        return $this->calculator->evaluate('v1', $offers, $this->now);
    }

    private function statusOf(OfferFacts ...$offers): array
    {
        $s = $this->evaluate(...$offers);

        return [$s->status, $s->reason];
    }

    private function offer(
        string $id,
        ?string $variantId = 'v1',
        string $status = 'matched',
        string $currency = 'CZK',
        int $price = 120000,
        ?int $fx = 1_000_000,
        int $shipping = 0,
        ?int $quantity = 4,
        ?int $leadMin = 2,
        ?int $leadMax = 5,
        ?\DateTimeImmutable $checkedAt = null,
    ): OfferFacts {
        return new OfferFacts($id, 'p1', $variantId, $status, $currency, $price, $checkedAt ?? $this->now->modify('-1 day'), $shipping, $fx, null, $quantity, $leadMin, $leadMax);
    }
}
