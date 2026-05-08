<?php

declare(strict_types=1);

namespace App\Tests\Pricing;

use App\Pricing\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testLandedCostConvertsPriceAndShippingAndRoundsHalfUp(): void
    {
        // 49.99 € + 4.99 € shipping at 25.315 CZK/EUR = 1391.8187 Kč
        self::assertSame(139182, Money::landedCost(4999, 499, 25_315_000));
        self::assertSame(129900, Money::landedCost(129900, 0, Money::RATE_ONE));
        self::assertSame(1, Money::landedCost(1, 0, 500_000));
        self::assertSame(0, Money::landedCost(1, 0, 499_999));
    }

    public function testMarkupRoundsUpAndShareRoundsDownToTenCrowns(): void
    {
        self::assertSame(20000, Money::markupUpTo10(12345, 6000)); // 197.52 Kč
        self::assertSame(12000, Money::markupUpTo10(10000, 2000)); // already whole
        self::assertSame(13000, Money::markupUpTo10(10001, 2000));
        self::assertSame(193000, Money::shareDownTo10(199900, 9700)); // 1939.03 Kč
    }

    public function testEurPriceIsRoundedToTenCents(): void
    {
        self::assertSame(7960, Money::czkToEur(199000, 25_000_000));
        self::assertSame(8000, Money::czkToEur(199900, 25_000_000)); // 79.96
        self::assertSame(4940, Money::czkToEur(123400, 25_000_000)); // 49.36
        self::assertSame(4010, Money::czkToEur(100125, 25_000_000)); // 40.05, half up
        self::assertSame(4000, Money::czkToEur(100124, 25_000_000));
        self::assertSame(70000, Money::eurToCzk(2800, 25_000_000));
    }

    public function testRatesAreParsedWithoutFloats(): void
    {
        self::assertSame(25_000_000, Money::parseRate('25.0'));
        self::assertSame(25_315_000, Money::parseRate(' 25.315 '));
        self::assertSame(1, Money::parseRate('0.000001'));
        foreach (['25,0', '0', '-1', '1.0000001', ''] as $bad) {
            try {
                Money::parseRate($bad);
                self::fail('Accepted '.$bad);
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testMarginIsOnLandedCostAndRoundsDown(): void
    {
        self::assertSame(1000, Money::marginBp(110000, 100000));
        self::assertSame(-1000, Money::marginBp(90000, 100000));
        self::assertSame(-1, Money::marginBp(99999, 100000));
        self::assertNull(Money::marginBp(1000, 0));
    }
}
