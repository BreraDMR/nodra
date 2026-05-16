<?php

declare(strict_types=1);

namespace App\Delivery;

final class DeliveryMethod
{
    /** free pickup at Anděl, place and time agreed by message */
    public const PICKUP_ANDEL = 'pickup_andel';
    /** NODRA delivers in person, Prague postal codes only */
    public const PRAGUE_PERSONAL = 'prague_personal';
    /** carrier within Czechia, off until the tariff is set */
    public const CARRIER_CZ = 'carrier_cz';

    public const ALL = [self::PICKUP_ANDEL, self::PRAGUE_PERSONAL, self::CARRIER_CZ];
}
