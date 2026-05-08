<?php

declare(strict_types=1);

namespace App\Tests\Pricing;

use App\Pricing\PriceCalculator;
use App\Pricing\PricingSettings;
use App\Tests\Support\PricingDefaults;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $prices;

    protected function setUp(): void
    {
        $this->prices = new PriceCalculator(PricingDefaults::settings());
    }

    public function testMarkupOnLandedCost(): void
    {
        $s = $this->prices->suggest(50000, 4000);

        self::assertSame([70000, 2800, 70000, null, false, 55000, false, 4000], [
            $s->priceCzk, $s->priceEur, $s->markupPriceCzk, $s->rrpCapCzk, $s->rrpCapped, $s->floorPriceCzk, $s->marginTooLow, $s->marginBp,
        ]);
    }

    public function testRrpCapsTheSuggestionRoundedDown(): void
    {
        // 700 Kč x 0.97 = 679 Kč, down to 670 Kč
        $capped = $this->prices->suggest(50000, 4000, rrpCzk: 70000);
        self::assertSame([67000, 2680, 67000, true, false, 3400], [$capped->priceCzk, $capped->priceEur, $capped->rrpCapCzk, $capped->rrpCapped, $capped->marginTooLow, $capped->marginBp]);

        // a higher RRP leaves the markup price alone
        $free = $this->prices->suggest(50000, 4000, rrpCzk: 80000);
        self::assertSame([70000, 77000, false], [$free->priceCzk, $free->rrpCapCzk, $free->rrpCapped]);
    }

    public function testMinimumMarginFloorWinsOverTheRrpCapAndIsFlagged(): void
    {
        // cap 533.50 -> 530 Kč is below cost x 1.10 = 550 Kč
        $s = $this->prices->suggest(50000, 4000, rrpCzk: 55000);
        self::assertSame([55000, 53000, true, true, 1000], [$s->priceCzk, $s->rrpCapCzk, $s->rrpCapped, $s->marginTooLow, $s->marginBp]);

        // the floor is rounded up to 10 Kč too
        self::assertSame(56000, $this->prices->suggest(50001, 4000, rrpCzk: 50000)->priceCzk);

        // a rule markup under the minimum margin is lifted and flagged the same way
        $low = $this->prices->suggest(50000, 500);
        self::assertSame([55000, 53000, true], [$low->priceCzk, $low->markupPriceCzk, $low->marginTooLow]);
    }

    public function testAboveMarketThreshold(): void
    {
        self::assertFalse($this->prices->aboveMarket(115000, 100000)); // exactly 15 % above is fine
        self::assertTrue($this->prices->aboveMarket(115001, 100000));
        self::assertFalse($this->prices->aboveMarket(999999, null));

        self::assertTrue($this->prices->suggest(50000, 4000, marketPriceCzk: 60000)->aboveMarket); // 700 > 690
        self::assertFalse($this->prices->suggest(50000, 4000, marketPriceCzk: 61000)->aboveMarket); // 700 < 701.50
    }

    public function testEurRrpAndDisplayRate(): void
    {
        self::assertSame(70000, $this->prices->rrpInCzk(2800, 'EUR'));
        self::assertSame(70000, $this->prices->rrpInCzk(70000, 'CZK'));
        self::assertNull($this->prices->rrpInCzk(null, null));

        $other = new PriceCalculator(new PricingSettings(7, 1, 9700, 1000, '24.5', 1500));
        self::assertSame(2860, $other->eur(70000)); // 28.571 €
    }
}
