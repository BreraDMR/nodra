<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Shipment;
use App\Entity\ShopOrder;

/** An order with its lines, shipments and ledger, as the rules and the actions read it. */
final class OrderState
{
    /**
     * @param list<OrderItem> $items
     * @param list<Shipment> $shipments
     * @param list<Payment> $payments
     */
    public function __construct(public readonly ShopOrder $order, private array $items, private array $shipments, private array $payments) {}

    /** @return list<OrderItem> */
    public function items(): array { return $this->items; }

    /** @return list<Shipment> */
    public function shipments(): array { return $this->shipments; }

    /** @return list<Payment> */
    public function payments(): array { return $this->payments; }

    public function addItem(OrderItem $item): void { $this->items[] = $item; }

    public function addPayment(Payment $payment): void { $this->payments[] = $payment; }

    public function item(string $id): ?OrderItem
    {
        foreach ($this->items as $item) {
            if ($item->getId()->toRfc4122() === strtolower($id)) {
                return $item;
            }
        }

        return null;
    }

    public function shipment(string $id): ?Shipment
    {
        foreach ($this->shipments as $shipment) {
            if ($shipment->getId()->toRfc4122() === strtolower($id)) {
                return $shipment;
            }
        }

        return null;
    }

    /** @return list<OrderItem> */
    public function activeItems(): array
    {
        return array_values(array_filter($this->items, static fn (OrderItem $item): bool => $item->isActive()));
    }

    /** @return list<OrderItem> */
    public function itemsOf(Shipment $shipment): array
    {
        return array_values(array_filter($this->items, static fn (OrderItem $item): bool => $item->getShipment()->getId()->equals($shipment->getId())));
    }

    /** @return list<OrderItem> */
    public function activeItemsOf(Shipment $shipment): array
    {
        return array_values(array_filter($this->itemsOf($shipment), static fn (OrderItem $item): bool => $item->isActive()));
    }

    /** Every active line has its goods: from own stock or received. Computed, never stored. */
    public function isReady(Shipment $shipment): bool
    {
        if (in_array($shipment->getStatus(), [ShipmentStatus::REFUSED, ShipmentStatus::CANCELLED], true)) {
            return false;
        }
        $active = $this->activeItemsOf($shipment);

        return $active !== [] && array_filter($active, static fn (OrderItem $item): bool => !$item->hasGoods()) === [];
    }

    public function isDelivered(OrderItem $item): bool
    {
        return $item->getShipment()->getStatus() === ShipmentStatus::HANDED_OVER;
    }

    /** Lead time of a shipment: its slowest active line, null while one of them is unknown. @return array{?int, ?int} */
    public function leadTime(Shipment $shipment): array
    {
        $items = $this->activeItemsOf($shipment) ?: $this->itemsOf($shipment);
        $min = $max = 0;
        foreach ($items as $item) {
            if ($item->getLeadTimeMinDays() === null || $item->getLeadTimeMaxDays() === null) {
                return [null, null];
            }
            $min = max($min, $item->getLeadTimeMinDays());
            $max = max($max, $item->getLeadTimeMaxDays());
        }

        return $items === [] ? [null, null] : [$min, $max];
    }

    public function paymentsMinor(): int { return $this->sum(Payment::PAYMENT); }

    public function refundsMinor(): int { return $this->sum(Payment::REFUND); }

    public function netPaidMinor(): int { return $this->paymentsMinor() - $this->refundsMinor(); }

    public function amountDueMinor(): int { return max(0, $this->order->getTotalMinor() - $this->netPaidMinor()); }

    public function refundDueMinor(): int { return max(0, $this->netPaidMinor() - $this->order->getTotalMinor()); }

    private function sum(string $kind): int
    {
        $total = 0;
        foreach ($this->payments as $payment) {
            if ($payment->getKind() === $kind) {
                $total += $payment->getAmountMinor();
            }
        }

        return $total;
    }
}
