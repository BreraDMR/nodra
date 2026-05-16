<?php

declare(strict_types=1);

namespace App\Order;

/** planned -> scheduled -> handed_over, or refused / cancelled. A refused shipment can be scheduled again. */
final class ShipmentStatus
{
    public const PLANNED = 'planned';
    public const SCHEDULED = 'scheduled';
    public const HANDED_OVER = 'handed_over';
    public const REFUSED = 'refused';
    public const CANCELLED = 'cancelled';

    public const ALL = [self::PLANNED, self::SCHEDULED, self::HANDED_OVER, self::REFUSED, self::CANCELLED];
}
