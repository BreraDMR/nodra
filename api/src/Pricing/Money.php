<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Integer money maths. Amounts are minor units (haléře, cents), rates integer millionths,
 * percentages basis points. No floats anywhere near a price.
 */
final class Money
{
    /** 1.0 as a rate in millionths */
    public const RATE_ONE = 1_000_000;
    /** 10 Kč in haléře, the step suggested prices are rounded to */
    public const TEN_CZK = 1_000;

    /** a / b rounded half up, for a >= 0 and b > 0 */
    public static function divRound(int $a, int $b): int
    {
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /** a / b rounded up, for a >= 0 and b > 0 */
    public static function divCeil(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }

    /** a / b rounded down, negative a included */
    public static function divFloor(int $a, int $b): int
    {
        $quotient = intdiv($a, $b);

        return ($a % $b !== 0 && ($a < 0) !== ($b < 0)) ? $quotient - 1 : $quotient;
    }

    /** "25.315" => 25315000. Settings hold rates as text, so nothing goes through a float. */
    public static function parseRate(string $rate): int
    {
        if (!preg_match('/^(\d{1,6})(?:\.(\d{1,6}))?$/', trim($rate), $parts)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a rate like 25.0', $rate));
        }
        $value = (int) $parts[1] * self::RATE_ONE + (int) str_pad($parts[2] ?? '', 6, '0');
        if ($value <= 0) {
            throw new \InvalidArgumentException('A rate must be above zero');
        }

        return $value;
    }

    /** round((price + shipping) * rate / 1 000 000), offer currency minor units to haléře */
    public static function landedCost(int $priceMinor, int $inboundShippingMinor, int $fxRateCzk): int
    {
        return self::divRound(($priceMinor + $inboundShippingMinor) * $fxRateCzk, self::RATE_ONE);
    }

    /** amount * (1 + bp), rounded up to whole 10 Kč */
    public static function markupUpTo10(int $amount, int $bp): int
    {
        return self::divCeil($amount * (10_000 + $bp), 10_000 * self::TEN_CZK) * self::TEN_CZK;
    }

    /** amount * bp, rounded down to whole 10 Kč */
    public static function shareDownTo10(int $amount, int $bp): int
    {
        return intdiv($amount * $bp, 10_000 * self::TEN_CZK) * self::TEN_CZK;
    }

    /** haléře to cents at CZK-per-EUR millionths, rounded to 0.10 € */
    public static function czkToEur(int $haler, int $eurRate): int
    {
        return self::divRound($haler * self::RATE_ONE, $eurRate * 10) * 10;
    }

    /** cents to haléře at CZK-per-EUR millionths */
    public static function eurToCzk(int $cents, int $eurRate): int
    {
        return self::divRound($cents * $eurRate, self::RATE_ONE);
    }

    /** (price - cost) / cost in basis points, rounded down; null without a cost */
    public static function marginBp(int $price, int $cost): ?int
    {
        return $cost > 0 ? self::divFloor(($price - $cost) * 10_000, $cost) : null;
    }
}
