<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\OrderItem;
use App\Entity\Shipment;
use App\Entity\ShopOrder;

/**
 * The admin's work queues, computed and never stored; an order can sit in several. The SQL twin in
 * App\Admin\OrderQueueSql counts and filters the list with the same conditions, the API tests keep the two equal.
 *
 * Promised date of a line = the day the order was (last) confirmed in Prague + the line's leadTimeMaxDays. A line is
 * delayed from the day after that while it's still `ordered`; lines without a lead time are never delayed.
 */
final class OrderQueues
{
    public const REVIEW = 'review';
    public const PROBLEM = 'problem';
    public const TO_PURCHASE = 'to_purchase';
    public const WAITING = 'waiting';
    public const DELAYED = 'delayed';
    public const TO_SCHEDULE = 'to_schedule';
    public const DELIVERING = 'delivering';
    public const UNPAID = 'unpaid';
    public const REFUND = 'refund';

    public const ALL = [self::REVIEW, self::PROBLEM, self::TO_PURCHASE, self::WAITING, self::DELAYED, self::TO_SCHEDULE, self::DELIVERING, self::UNPAID, self::REFUND];

    /** the shop's calendar: dates are Prague dates */
    public const TIMEZONE = 'Europe/Prague';

    /** @return list<string> the queues the order is in, in ALL order */
    public static function of(OrderState $s, \DateTimeImmutable $today): array
    {
        $status = $s->order->getStatus();
        $active = $s->activeItems();
        $procurement = static fn (string $wanted): bool => array_filter($active, static fn (OrderItem $item): bool => $item->getProcurementStatus() === $wanted) !== [];
        $shipment = static fn (callable $test): bool => array_filter($s->shipments(), $test) !== [];

        $in = [
            self::REVIEW => $status === OrderStatus::REQUESTED,
            self::PROBLEM => $procurement(Procurement::FAILED),
            self::TO_PURCHASE => $status === OrderStatus::CONFIRMED && $procurement(Procurement::TO_ORDER),
            self::WAITING => $procurement(Procurement::ORDERED),
            self::DELAYED => array_filter($active, static fn (OrderItem $item): bool => self::isDelayed($s->order, $item, $today)) !== [],
            // scheduling is open only on a confirmed order, a requested one sits in review
            self::TO_SCHEDULE => $status === OrderStatus::CONFIRMED
                && $shipment(static fn (Shipment $x): bool => $x->getStatus() === ShipmentStatus::PLANNED && $s->isReady($x)),
            self::DELIVERING => $shipment(static fn (Shipment $x): bool => $x->getStatus() === ShipmentStatus::SCHEDULED),
            self::UNPAID => $s->amountDueMinor() > 0 && $shipment(static fn (Shipment $x): bool => $x->getStatus() === ShipmentStatus::HANDED_OVER),
            self::REFUND => $s->refundDueMinor() > 0,
        ];

        return array_keys(array_filter($in));
    }

    /** The Prague date the line was promised for, or null without a confirmation or a lead time. */
    public static function promisedDate(ShopOrder $order, OrderItem $item): ?\DateTimeImmutable
    {
        $confirmedAt = $order->getConfirmedAt();
        $days = $item->getLeadTimeMaxDays();
        if ($confirmedAt === null || $days === null) {
            return null;
        }

        return self::date($confirmedAt)->modify(sprintf('+%d days', $days));
    }

    /** Still ordered, and today is past the promised date. On the promised day itself it isn't late yet. */
    public static function isDelayed(ShopOrder $order, OrderItem $item, \DateTimeImmutable $today): bool
    {
        $promised = self::promisedDate($order, $item);

        return $item->isActive() && $item->getProcurementStatus() === Procurement::ORDERED
            && $promised !== null && self::date($today) > $promised;
    }

    /** Midnight of the Prague date of a moment. */
    public static function date(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
    }
}
