<?php

declare(strict_types=1);

namespace App\Order;

/** requested -> confirmed -> completed; requested or confirmed -> cancelled. */
final class OrderStatus
{
    /** NODRA still checks the offer and agrees price and date with the customer */
    public const REQUESTED = 'requested';
    /** the customer agreed */
    public const CONFIRMED = 'confirmed';
    /** every active line delivered and paid, set automatically */
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const ALL = [self::REQUESTED, self::CONFIRMED, self::COMPLETED, self::CANCELLED];
}
