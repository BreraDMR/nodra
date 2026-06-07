<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Shipment;

/**
 * What the admin may do with an order right now. The same lists are shown in the admin order detail and checked
 * before every action, so the screen and the API can't disagree.
 */
final class OrderRules
{
    // on the order
    public const CONFIRM = 'confirm';
    public const CANCEL = 'cancel';
    public const RECORD_PAYMENT = 'record_payment';
    public const RECORD_REFUND = 'record_refund';
    // on a line
    public const CHANGE_TERMS = 'change_terms';
    public const MARK_ORDERED = 'mark_ordered';
    public const MARK_RECEIVED = 'mark_received';
    public const MARK_FAILED = 'mark_failed';
    public const CANCEL_LINE = 'cancel';
    public const REPLACE = 'replace';
    public const RETURN = 'return';
    // on a shipment
    public const SCHEDULE = 'schedule';
    public const HAND_OVER = 'hand_over';
    public const REFUSE = 'refuse';
    // corrections, each with a reason in the journal
    public const MOVE = 'move';
    public const UNDO_RECEIVED = 'undo_received';
    public const RESCHEDULE = 'reschedule';
    public const VOID = 'void';

    /** How the customer can agree to a confirmation or a replacement. */
    public const AGREEMENT_CHANNELS = ['whatsapp', 'telegram', 'phone', 'email', 'in_person'];

    /** @return list<string> */
    public function orderActions(OrderState $s): array
    {
        $status = $s->order->getStatus();
        $open = self::isOpen($status);
        $actions = [];
        if ($status === OrderStatus::REQUESTED && $s->activeItems() !== [] && !self::hasActiveFailedLine($s)) {
            $actions[] = self::CONFIRM;
        }
        if ($open && !self::anyHandedOver($s)) {
            $actions[] = self::CANCEL;
        }
        // a completed order owes money again only after a payment was voided
        if (($open || $status === OrderStatus::COMPLETED) && $s->amountDueMinor() > 0) {
            $actions[] = self::RECORD_PAYMENT;
        }
        if ($s->netPaidMinor() > 0) {
            $actions[] = self::RECORD_REFUND;
        }

        return $actions;
    }

    /** @return list<string> */
    public function itemActions(OrderState $s, OrderItem $item): array
    {
        $status = $s->order->getStatus();
        $open = self::isOpen($status);
        $procurement = $item->getProcurementStatus();
        $shipmentStatus = $item->getShipment()->getStatus();

        if (!$item->isActive()) {
            // goods still on their way for a cancelled line go to own stock when they arrive
            return $item->getState() === LineState::CANCELLED && $procurement === Procurement::ORDERED ? [self::MARK_RECEIVED] : [];
        }

        $notHandedOver = in_array($shipmentStatus, [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED, ShipmentStatus::REFUSED], true);
        $actions = [];
        if ($open && $notHandedOver) {
            $actions[] = self::CHANGE_TERMS;
        }
        // money is spent on a supplier only after the customer agreed
        if ($status === OrderStatus::CONFIRMED && $procurement === Procurement::TO_ORDER) {
            $actions[] = self::MARK_ORDERED;
        }
        if ($open && $procurement === Procurement::ORDERED) {
            $actions[] = self::MARK_RECEIVED;
        }
        if ($open && in_array($procurement, [Procurement::TO_ORDER, Procurement::ORDERED], true)) {
            $actions[] = self::MARK_FAILED;
        }
        if ($open && $notHandedOver) {
            $actions[] = self::CANCEL_LINE;
        }
        if ($open && $procurement === Procurement::FAILED && in_array($shipmentStatus, [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED], true)) {
            $actions[] = self::REPLACE;
        }
        if ($shipmentStatus === ShipmentStatus::HANDED_OVER && in_array($status, [OrderStatus::CONFIRMED, OrderStatus::COMPLETED], true)) {
            $actions[] = self::RETURN;
        }

        return $actions;
    }

    /** @return list<string> */
    public function shipmentActions(OrderState $s, Shipment $shipment): array
    {
        if ($s->order->getStatus() !== OrderStatus::CONFIRMED) {
            return [];
        }
        $status = $shipment->getStatus();
        $actions = [];
        if (in_array($status, [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED, ShipmentStatus::REFUSED], true) && $s->activeItemsOf($shipment) !== []) {
            $actions[] = self::SCHEDULE;
        }
        if ($status === ShipmentStatus::SCHEDULED && $s->isReady($shipment)) {
            $actions[] = self::HAND_OVER;
            $actions[] = self::REFUSE;
        }

        return $actions;
    }

    /**
     * Fixing a line: move it to another open shipment or a new part, or take back a mistaken "received". Only while
     * the order is open and the line's shipment is planned or scheduled; a refused shipment gave its goods to own stock.
     *
     * @return list<string>
     */
    public function itemCorrections(OrderState $s, OrderItem $item): array
    {
        if (!$item->isActive() || !self::isOpen($s->order->getStatus())
            || !in_array($item->getShipment()->getStatus(), [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED], true)) {
            return [];
        }
        $corrections = [self::MOVE];
        if ($item->getProcurementStatus() === Procurement::RECEIVED) {
            $corrections[] = self::UNDO_RECEIVED;
        }

        return $corrections;
    }

    /** @return list<string> */
    public function shipmentCorrections(OrderState $s, Shipment $shipment): array
    {
        return $s->order->getStatus() === OrderStatus::CONFIRMED && $shipment->getStatus() === ShipmentStatus::SCHEDULED ? [self::RESCHEDULE] : [];
    }

    /** A payment or a refund can be voided once, in any order status; a correction itself can't. @return list<string> */
    public function paymentCorrections(OrderState $s, Payment $entry): array
    {
        return $entry->getKind() !== Payment::CORRECTION && $s->correctionOf($entry) === null ? [self::VOID] : [];
    }

    /** @param list<string> $allowed */
    public function require(string $action, array $allowed, string $subject): void
    {
        if (!in_array($action, $allowed, true)) {
            throw OrderProblem::notAllowed($action, sprintf('"%s" is not allowed on this %s right now', $action, $subject));
        }
    }

    /** Every active line delivered and enough money in: the order completes by itself. */
    public function shouldComplete(OrderState $s): bool
    {
        $active = $s->activeItems();

        return $s->order->getStatus() === OrderStatus::CONFIRMED
            && $active !== []
            && array_filter($active, static fn (OrderItem $item): bool => !$s->isDelivered($item)) === []
            && PaymentStatus::settles($s->paymentsMinor(), $s->refundsMinor(), $s->order->getTotalMinor());
    }

    private static function isOpen(string $status): bool
    {
        return in_array($status, [OrderStatus::REQUESTED, OrderStatus::CONFIRMED], true);
    }

    private static function hasActiveFailedLine(OrderState $s): bool
    {
        return array_filter($s->activeItems(), static fn (OrderItem $item): bool => $item->getProcurementStatus() === Procurement::FAILED) !== [];
    }

    private static function anyHandedOver(OrderState $s): bool
    {
        return array_filter($s->shipments(), static fn (Shipment $shipment): bool => $shipment->getStatus() === ShipmentStatus::HANDED_OVER) !== [];
    }
}
