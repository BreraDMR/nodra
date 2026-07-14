<?php

declare(strict_types=1);

namespace App\Admin;

use App\Delivery\DeliveryRules;
use App\Delivery\DeliverySettings;
use App\Entity\Payment;
use App\Order\PaymentSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The order settings as the admin screens need them (read-only; they live in config/services.yaml). */
final class SettingsView
{
    public function __construct(
        private DeliverySettings $delivery,
        private DeliveryRules $rules,
        private PaymentSettings $payment,
        #[Autowire(param: 'app.legal.privacy_version')] private string $privacyVersion,
    ) {}

    public function toArray(): array
    {
        return [
            'currency' => 'CZK',
            'payment' => [
                'handoverMethods' => $this->payment->handoverMethods,
                'paymentMethods' => $this->payment->enabled(Payment::PAYMENT),
                'refundMethods' => $this->payment->enabled(Payment::REFUND),
            ],
            'delivery' => [
                'methods' => $this->rules->offered(),
                'pragueFeeMinor' => $this->delivery->pragueFeeMinor,
                'pragueFreeFromMinor' => $this->delivery->pragueFreeFromMinor,
                'carrierFeeMinor' => $this->delivery->carrierFeeMinor,
                'carrierCodFeeMinor' => $this->delivery->carrierCodFeeMinor,
                'pickupNote' => array_combine(DeliverySettings::LOCALES, array_map($this->delivery->pickupNote(...), DeliverySettings::LOCALES)),
            ],
            'privacyVersion' => $this->privacyVersion,
        ];
    }
}
