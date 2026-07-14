<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\Payment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Which payment methods the admin can record, from app.payment.* and the carrier COD setting. */
final readonly class PaymentSettings
{
    /** @param list<string> $handoverMethods */
    public function __construct(
        #[Autowire(param: 'app.payment.handover_methods')] public array $handoverMethods,
        #[Autowire(param: 'app.delivery.carrier_cod_fee_minor')] public ?int $carrierCodFeeMinor,
    ) {
        foreach ($handoverMethods as $method) {
            if (!in_array($method, Payment::METHODS, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown handover payment method "%s"', $method));
            }
        }
    }

    public function accepts(string $kind, string $method): bool
    {
        if (in_array($method, $this->handoverMethods, true)) {
            return true;
        }

        // the carrier pays COD money out to NODRA, there's nothing to refund that way
        return $kind === Payment::PAYMENT && $method === 'carrier_cod' && $this->carrierCodFeeMinor !== null;
    }

    /** @return list<string> the methods of this kind the shop accepts right now, in the fixed order */
    public function enabled(string $kind): array
    {
        return array_values(array_filter(Payment::METHODS, fn (string $method): bool => $this->accepts($kind, $method)));
    }
}
