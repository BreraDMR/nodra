<?php

declare(strict_types=1);

namespace App\Delivery;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The app.delivery.* parameters from config/services.yaml. A null fee means the method is off. */
final readonly class DeliverySettings
{
    public function __construct(
        #[Autowire(param: 'app.delivery.prague_fee_minor')] public int $pragueFeeMinor,
        #[Autowire(param: 'app.delivery.prague_free_from_minor')] public int $pragueFreeFromMinor,
        #[Autowire(param: 'app.delivery.carrier_fee_minor')] public ?int $carrierFeeMinor,
        #[Autowire(param: 'app.delivery.carrier_cod_fee_minor')] public ?int $carrierCodFeeMinor,
        #[Autowire(param: 'app.delivery.pickup_note')] public string $pickupNote,
    ) {}
}
