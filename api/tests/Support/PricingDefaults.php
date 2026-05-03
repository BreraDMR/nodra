<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Pricing\AvailabilityService;
use App\Pricing\PricingData;
use App\Pricing\PricingSettings;
use App\Pricing\SourcingCalculator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;

/** The spec defaults (same as config/services.yaml), for tests that build services by hand. */
final class PricingDefaults
{
    public static function settings(): PricingSettings
    {
        return new PricingSettings(7, 1, 9700, 1000, '25.0', 1500);
    }

    public static function availability(Connection $db): AvailabilityService
    {
        return new AvailabilityService($db, new PricingData($db), new SourcingCalculator(self::settings()), new MockClock());
    }
}
