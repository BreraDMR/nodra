<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Delivery\DeliveryOption;
use App\Delivery\PlannedShipment;

/** A priced basket for one delivery method. */
final readonly class Quote
{
    /**
     * @param list<BasketLine> $lines
     * @param list<array{method: string, available: bool, reason: ?string, feeMinor: ?int, note: ?string, freeFromMinor: ?int}> $methods
     * @param ?string $methodProblem why the chosen method can't deliver here, null when it can
     * @param ?array{together: DeliveryOption, split: ?DeliveryOption} $options null while the method or a line is unavailable
     */
    public function __construct(
        public array $lines,
        public int $subtotalMinor,
        public array $methods,
        public string $method,
        public ?string $postalCode,
        public ?string $methodProblem,
        public ?array $options,
    ) {}

    public function unavailableLine(): ?BasketLine
    {
        foreach ($this->lines as $line) {
            if ($line->isUnavailable()) {
                return $line;
            }
        }

        return null;
    }

    public function option(string $fulfilment): ?DeliveryOption
    {
        return $this->options[$fulfilment] ?? null;
    }

    public function toArray(): array
    {
        $price = static fn (int $amount): array => ['amount' => $amount, 'currency' => 'CZK'];
        $option = fn (?DeliveryOption $option): ?array => $option === null ? null : [
            'fulfilment' => $option->fulfilment,
            'shipments' => array_map(static fn (PlannedShipment $s): array => [
                'number' => $s->number, 'variantIds' => $s->keys,
                'leadTimeMinDays' => $s->leadTimeMinDays, 'leadTimeMaxDays' => $s->leadTimeMaxDays,
                'fee' => $price($s->feeMinor),
            ], $option->shipments),
            'shipping' => $price($option->shippingMinor()),
            'total' => $price($this->subtotalMinor + $option->shippingMinor()),
        ];

        return [
            'currency' => 'CZK',
            'lines' => array_map(static fn (BasketLine $line): array => [
                'variantId' => $line->variantId, 'name' => $line->name, 'variant' => $line->label, 'sku' => $line->sku,
                'quantity' => $line->quantity, 'unitPrice' => $line->unitPriceMinor, 'lineTotal' => $line->lineTotalMinor(),
                'availability' => $line->sourcing->toPublic(),
            ], $this->lines),
            'subtotal' => $price($this->subtotalMinor),
            'methods' => array_map(static fn (array $m): array => [
                'method' => $m['method'], 'available' => $m['available'], 'reason' => $m['reason'],
                'fee' => $m['feeMinor'] === null ? null : $price($m['feeMinor']), 'note' => $m['note'],
                'freeFromMinor' => $m['freeFromMinor'],
            ], $this->methods),
            'delivery' => ['method' => $this->method, 'postalCode' => $this->postalCode],
            'options' => $this->options === null ? null : ['together' => $option($this->options['together']), 'split' => $option($this->options['split'])],
            'canCheckout' => $this->options !== null,
        ];
    }
}
