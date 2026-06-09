<?php

declare(strict_types=1);

namespace App\Delivery;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The app.delivery.* parameters from config/services.yaml. A null fee means the method is off. */
final readonly class DeliverySettings
{
    public const LOCALES = ['cs', 'de', 'en'];

    /** @param array<string, string> $pickupNotes pickup place and time note per language */
    public function __construct(
        #[Autowire(param: 'app.delivery.prague_fee_minor')] public int $pragueFeeMinor,
        #[Autowire(param: 'app.delivery.prague_free_from_minor')] public int $pragueFreeFromMinor,
        #[Autowire(param: 'app.delivery.carrier_fee_minor')] public ?int $carrierFeeMinor,
        #[Autowire(param: 'app.delivery.carrier_cod_fee_minor')] public ?int $carrierCodFeeMinor,
        #[Autowire(param: 'app.delivery.pickup_note')] public array $pickupNotes,
    ) {
        foreach (self::LOCALES as $locale) {
            if (!is_string($pickupNotes[$locale] ?? null) || trim($pickupNotes[$locale]) === '') {
                throw new \InvalidArgumentException(sprintf('app.delivery.pickup_note needs a "%s" text', $locale));
            }
        }
    }

    public function pickupNote(string $locale): string
    {
        return $this->pickupNotes[$locale] ?? $this->pickupNotes['en'];
    }
}
