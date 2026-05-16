<?php

declare(strict_types=1);

namespace App\Order;

/** Where the goods of an order line come from, and how far buying them got. */
final class Procurement
{
    /** covered by own stock, reserved at checkout */
    public const FROM_STOCK = 'from_stock';
    /** bought from a supplier after confirmation */
    public const TO_ORDER = 'to_order';
    public const ORDERED = 'ordered';
    public const RECEIVED = 'received';
    public const FAILED = 'failed';

    public const ALL = [self::FROM_STOCK, self::TO_ORDER, self::ORDERED, self::RECEIVED, self::FAILED];
}
