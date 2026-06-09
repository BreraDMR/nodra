<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Delivery\DeliveryRules;
use App\Delivery\PostalCode;
use App\Delivery\ShipmentPlanner;

final class QuoteBuilder
{
    public function __construct(private DeliveryRules $rules, private ShipmentPlanner $planner) {}

    /**
     * @param non-empty-list<BasketLine> $lines
     * @param string $locale language of the method notes
     */
    public function build(array $lines, string $method, ?string $postalCode, string $locale): Quote
    {
        $subtotal = array_sum(array_map(static fn (BasketLine $line): int => $line->lineTotalMinor(), $lines));
        $methods = [];
        foreach ($this->rules->offered() as $offered) {
            $reason = $this->rules->unavailableReason($offered, $postalCode);
            $methods[] = [
                'method' => $offered, 'available' => $reason === null, 'reason' => $reason,
                'feeMinor' => $reason === null ? $this->rules->fee($offered, $subtotal) : null, 'note' => $this->rules->note($offered, $locale),
                'freeFromMinor' => $this->rules->freeFrom($offered),
            ];
        }
        $problem = $this->rules->unavailableReason($method, $postalCode);
        $blocked = array_filter($lines, static fn (BasketLine $line): bool => $line->isUnavailable()) !== [];
        $options = $problem === null && !$blocked
            ? $this->planner->options(array_map(static fn (BasketLine $line) => $line->planned(), $lines), $method, $subtotal)
            : null;

        return new Quote($lines, $subtotal, $methods, $method, PostalCode::normalize($postalCode), $problem, $options);
    }
}
