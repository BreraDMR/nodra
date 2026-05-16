<?php

declare(strict_types=1);

namespace App\Order;

/**
 * Payment status of an order, always derived from its ledger and current total.
 *
 * A cancelled line lowers the total but not what was paid, so an order paid in full stays `paid` and shows the
 * difference as a refund due until the refund is recorded.
 */
final class PaymentStatus
{
    public const UNPAID = 'unpaid';
    public const PARTIALLY_PAID = 'partially_paid';
    public const PAID = 'paid';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const REFUNDED = 'refunded';

    public const ALL = [self::UNPAID, self::PARTIALLY_PAID, self::PAID, self::PARTIALLY_REFUNDED, self::REFUNDED];

    /** @param int $payments sum of payments, $refunds sum of refunds, $total the order total, all minor units */
    public static function of(int $payments, int $refunds, int $total): string
    {
        if ($payments <= 0) {
            return self::UNPAID;
        }
        $net = $payments - $refunds;
        if ($refunds <= 0) {
            return $net >= $total ? self::PAID : self::PARTIALLY_PAID;
        }

        return $net <= 0 ? self::REFUNDED : self::PARTIALLY_REFUNDED;
    }

    /**
     * Enough money is in to complete the order: `paid`, or `partially_refunded` when what's left still covers the
     * total (a line was cancelled after payment and its money given back).
     */
    public static function settles(int $payments, int $refunds, int $total): bool
    {
        return $payments > 0 && $payments - $refunds >= $total
            && in_array(self::of($payments, $refunds, $total), [self::PAID, self::PARTIALLY_REFUNDED], true);
    }
}
