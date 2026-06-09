<?php

declare(strict_types=1);

namespace App\Delivery;

/** Which methods are offered, where they go and what one shipment costs. The only place fees are computed. */
final class DeliveryRules
{
    public const POSTAL_CODE_REQUIRED = 'postal_code_required';
    public const INVALID_POSTAL_CODE = 'invalid_postal_code';
    public const OUTSIDE_PRAGUE = 'outside_prague';
    public const NOT_OFFERED = 'not_offered';

    public function __construct(private DeliverySettings $settings) {}

    /** @return list<string> methods offered at all; carrier only once it has a tariff */
    public function offered(): array
    {
        return array_values(array_filter(DeliveryMethod::ALL, fn (string $method): bool => $method !== DeliveryMethod::CARRIER_CZ || $this->settings->carrierFeeMinor !== null));
    }

    /** Why the method can't deliver to this postal code, or null when it can. */
    public function unavailableReason(string $method, ?string $postalCode): ?string
    {
        if (!in_array($method, $this->offered(), true)) {
            return self::NOT_OFFERED;
        }
        if ($method === DeliveryMethod::PICKUP_ANDEL) {
            return null;
        }
        if (trim((string) $postalCode) === '') {
            return self::POSTAL_CODE_REQUIRED;
        }
        $normalized = PostalCode::normalize($postalCode);
        if ($normalized === null) {
            return self::INVALID_POSTAL_CODE;
        }

        return $method === DeliveryMethod::PRAGUE_PERSONAL && !PostalCode::isPrague($normalized) ? self::OUTSIDE_PRAGUE : null;
    }

    /** Fee of one shipment, whatever part of the order it carries; the threshold looks at the whole goods subtotal. */
    public function fee(string $method, int $goodsSubtotalMinor): int
    {
        return match ($method) {
            DeliveryMethod::PICKUP_ANDEL => 0,
            DeliveryMethod::PRAGUE_PERSONAL => $goodsSubtotalMinor >= $this->settings->pragueFreeFromMinor ? 0 : $this->settings->pragueFeeMinor,
            DeliveryMethod::CARRIER_CZ => $this->settings->carrierFeeMinor ?? throw new \DomainException('Carrier delivery is not offered'),
            default => throw new \InvalidArgumentException('Unknown delivery method'),
        };
    }

    public function note(string $method, string $locale): ?string
    {
        return $method === DeliveryMethod::PICKUP_ANDEL ? $this->settings->pickupNote($locale) : null;
    }

    /** Goods subtotal from which the method costs nothing, or null when the fee doesn't depend on it. */
    public function freeFrom(string $method): ?int
    {
        return $method === DeliveryMethod::PRAGUE_PERSONAL ? $this->settings->pragueFreeFromMinor : null;
    }
}
