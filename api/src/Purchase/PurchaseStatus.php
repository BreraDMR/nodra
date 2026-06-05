<?php

declare(strict_types=1);

namespace App\Purchase;

/** ordered -> received, or ordered -> cancelled. */
final class PurchaseStatus
{
    public const ORDERED = 'ordered';
    public const RECEIVED = 'received';
    public const CANCELLED = 'cancelled';

    public const ALL = [self::ORDERED, self::RECEIVED, self::CANCELLED];
}
