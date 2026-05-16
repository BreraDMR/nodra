<?php

declare(strict_types=1);

namespace App\Order;

final class LineState
{
    public const ACTIVE = 'active';
    public const CANCELLED = 'cancelled';
    /** taken back after handover */
    public const RETURNED = 'returned';

    public const ALL = [self::ACTIVE, self::CANCELLED, self::RETURNED];
}
