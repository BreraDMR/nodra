<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Delivery\DeliveryRules;
use App\Delivery\DeliverySettings;
use App\Delivery\ShipmentPlanner;

/** The D04 delivery settings (same as config/services.yaml), for tests that build services by hand. */
final class OrderDefaults
{
    public const PICKUP_NOTES = [
        'cs' => 'Místo a čas předání na Andělu domluvíme zprávou.',
        'de' => 'Ort und Zeit der Übergabe am Anděl vereinbaren wir per Nachricht.',
        'en' => 'We agree the place and time at Anděl by message.',
    ];

    public static function settings(?int $carrierFeeMinor = null): DeliverySettings
    {
        return new DeliverySettings(14900, 50000, $carrierFeeMinor, null, self::PICKUP_NOTES);
    }

    public static function rules(?int $carrierFeeMinor = null): DeliveryRules
    {
        return new DeliveryRules(self::settings($carrierFeeMinor));
    }

    public static function planner(?int $carrierFeeMinor = null): ShipmentPlanner
    {
        return new ShipmentPlanner(self::rules($carrierFeeMinor));
    }
}
