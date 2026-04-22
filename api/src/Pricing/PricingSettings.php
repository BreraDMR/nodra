<?php

declare(strict_types=1);

namespace App\Pricing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The app.pricing.* parameters from config/services.yaml. */
final readonly class PricingSettings
{
    /** CZK per EUR in millionths */
    public int $eurRate;

    public function __construct(
        #[Autowire(param: 'app.pricing.fresh_offer_days')] public int $freshOfferDays,
        #[Autowire(param: 'app.pricing.handling_days')] public int $handlingDays,
        #[Autowire(param: 'app.pricing.rrp_factor_bp')] public int $rrpFactorBp,
        #[Autowire(param: 'app.pricing.min_margin_bp')] public int $minMarginBp,
        #[Autowire(param: 'app.pricing.eur_display_rate')] string $eurDisplayRate,
        #[Autowire(param: 'app.pricing.above_market_bp')] public int $aboveMarketBp,
    ) {
        $this->eurRate = Money::parseRate($eurDisplayRate);
    }
}
