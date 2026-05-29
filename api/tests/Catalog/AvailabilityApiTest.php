<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AvailabilityApiTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    private const ORDERABLE_2_4 = ['status' => 'orderable', 'leadTimeMinDays' => 2, 'leadTimeMaxDays' => 4];
    private const CHECK = ['status' => 'check_needed', 'leadTimeMinDays' => null, 'leadTimeMaxDays' => null];
    private const UNAVAILABLE = ['status' => 'unavailable', 'leadTimeMinDays' => null, 'leadTimeMaxDays' => null];

    public function testCardsAndVariantsShowAvailabilityWithHandlingDay(): void
    {
        $b = $this->builder();
        $category = $b->category('t-avail');
        $mixed = $b->product('t-mixed', $category);
        $b->pricedOffer($mixed, $b->variant($mixed, 'T-MIXED-QUICK'), 50000, leadTimeMinDays: 1, leadTimeMaxDays: 3);
        $b->pricedOffer($mixed, $b->variant($mixed, 'T-MIXED-SLOW'), 50000, leadTimeMinDays: 4, leadTimeMaxDays: 6);
        $b->pricedOffer($mixed, $b->variant($mixed, 'T-MIXED-STALE'), 50000, checkedAt: new \DateTimeImmutable('-10 days'));
        $b->pricedOffer($mixed, $b->variant($mixed, 'T-MIXED-UNKNOWN'), 50000, leadTimeMaxDays: null);
        $b->variant($mixed, 'T-MIXED-NONE');
        $snapshot = $b->product('t-snapshot', $category);
        $b->variant($snapshot, 'T-SNAPSHOT-1');
        $b->pricedOffer($snapshot, null, 50000);
        $nothing = $b->product('t-nothing', $category);
        $b->variant($nothing, 'T-NOTHING-1');

        $cards = array_column($this->getJson('/api/products', ['category' => 't-avail', 'locale' => 'cs'])['items'], 'availability', 'slug');

        self::assertSame(['t-mixed' => self::ORDERABLE_2_4, 't-nothing' => self::UNAVAILABLE, 't-snapshot' => self::CHECK], $cards);

        $detail = $this->getJson('/api/products/t-mixed', ['locale' => 'de']);
        self::assertSame(self::ORDERABLE_2_4, $detail['availability']);
        self::assertSame([
            'T-MIXED-NONE' => self::UNAVAILABLE,
            'T-MIXED-QUICK' => self::ORDERABLE_2_4,
            'T-MIXED-SLOW' => ['status' => 'orderable', 'leadTimeMinDays' => 5, 'leadTimeMaxDays' => 7],
            'T-MIXED-STALE' => self::CHECK,
            'T-MIXED-UNKNOWN' => self::CHECK,
        ], array_column($detail['variants'], 'availability', 'sku'));
        self::assertSame(self::CHECK, $this->getJson('/api/products/t-snapshot')['variants'][0]['availability']);
    }

    public function testOwnStockMakesAVariantOrderableInTheHandlingDays(): void
    {
        $b = $this->builder();
        $product = $b->product('t-held', $b->category('t-held-cat'));
        $b->variant($product, 'T-HELD-STOCK', stock: 1);
        $b->pricedOffer($product, $b->variant($product, 'T-HELD-OFFER'), 50000, leadTimeMinDays: 4, leadTimeMaxDays: 6);
        $b->variant($product, 'T-HELD-NONE');

        $detail = $this->getJson('/api/products/t-held');

        self::assertSame([
            'T-HELD-NONE' => self::UNAVAILABLE,
            'T-HELD-OFFER' => ['status' => 'orderable', 'leadTimeMinDays' => 5, 'leadTimeMaxDays' => 7],
            'T-HELD-STOCK' => ['status' => 'orderable', 'leadTimeMinDays' => 1, 'leadTimeMaxDays' => 1],
        ], array_column($detail['variants'], 'availability', 'sku'));
        self::assertSame(['status' => 'orderable', 'leadTimeMinDays' => 1, 'leadTimeMaxDays' => 1], $detail['availability']);
    }

    public function testAvailableOnlyMeansOrderable(): void
    {
        $b = $this->builder();
        $category = $b->category('t-only');
        $held = $b->product('t-only-held', $category);
        $b->variant($held, 'T-ONLY-HELD', stock: 2);
        $offered = $b->product('t-only-offered', $category);
        $b->pricedOffer($offered, $b->variant($offered, 'T-ONLY-OFFERED'), 50000);
        $snapshot = $b->product('t-only-snapshot', $category);
        $b->variant($snapshot, 'T-ONLY-SNAPSHOT');
        $b->pricedOffer($snapshot, null, 50000);
        $nothing = $b->product('t-only-nothing', $category);
        $b->variant($nothing, 'T-ONLY-NOTHING');

        $all = $this->getJson('/api/products', ['category' => 't-only']);
        $orderable = $this->getJson('/api/products', ['category' => 't-only', 'availableOnly' => 'true']);

        self::assertSame(4, $all['total']);
        self::assertSame(['t-only-held', 't-only-offered'], array_column($orderable['items'], 'slug'));
        self::assertSame(2, $orderable['total']);
        // a supplier's snapshot quantity is not NODRA's stock
        self::assertSame([true, false], [$orderable['items'][0]['inStock'], $orderable['items'][1]['inStock']]);
    }

    public function testOfferIsFreshForExactlySevenDays(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-09-28T12:00:00+00:00'));
        $b = $this->builder();
        $product = $b->product('t-fresh', $b->category('t-fresh-cat'));
        $b->pricedOffer($product, $b->variant($product, 'T-FRESH-EXACT'), 50000, checkedAt: new \DateTimeImmutable('2026-09-21T12:00:00+00:00'));
        $b->pricedOffer($product, $b->variant($product, 'T-FRESH-OVER'), 50000, checkedAt: new \DateTimeImmutable('2026-09-21T11:59:59+00:00'));

        $variants = array_column($this->getJson('/api/products/t-fresh')['variants'], 'availability', 'sku');

        self::assertSame(['status' => 'orderable', 'leadTimeMinDays' => 3, 'leadTimeMaxDays' => 6], $variants['T-FRESH-EXACT']);
        self::assertSame(self::CHECK, $variants['T-FRESH-OVER']);
    }
}
