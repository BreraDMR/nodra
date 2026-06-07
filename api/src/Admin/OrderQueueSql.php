<?php

declare(strict_types=1);

namespace App\Admin;

use App\Order\OrderQueues;

/**
 * The queues of App\Order\OrderQueues as SQL conditions on `shop_order o`, for counts and the orders list. Each one
 * is a few indexed EXISTS lookups per order, so a count over all orders is one query. They need the :today parameter
 * (a Prague date, Y-m-d) for the delayed queue.
 */
final class OrderQueueSql
{
    /** payments minus refunds, voided entries cancelled by their correction */
    private const NET_PAID = "(SELECT COALESCE(SUM(CASE WHEN p.kind = 'payment' THEN p.amount_minor WHEN p.kind = 'refund' THEN -p.amount_minor
        WHEN c.kind = 'payment' THEN -p.amount_minor ELSE p.amount_minor END), 0)
        FROM payment p LEFT JOIN payment c ON c.id = p.corrects_id WHERE p.order_id = o.id)";

    /** @return array<string, string> queue => condition, in OrderQueues::ALL order */
    public static function conditions(): array
    {
        $line = static fn (string $procurement): string => "EXISTS (SELECT 1 FROM order_item i WHERE i.order_id = o.id AND i.state = 'active' AND i.procurement_status = '$procurement')";
        $shipment = static fn (string $status): string => "EXISTS (SELECT 1 FROM shipment s WHERE s.order_id = o.id AND s.status = '$status')";

        return [
            OrderQueues::REVIEW => "o.status = 'requested'",
            OrderQueues::PROBLEM => $line('failed'),
            OrderQueues::TO_PURCHASE => "o.status = 'confirmed' AND ".$line('to_order'),
            OrderQueues::WAITING => $line('ordered'),
            OrderQueues::DELAYED => "o.confirmed_at IS NOT NULL AND EXISTS (SELECT 1 FROM order_item i WHERE i.order_id = o.id AND i.state = 'active'
                AND i.procurement_status = 'ordered' AND i.lead_time_max_days IS NOT NULL
                AND CAST(o.confirmed_at AT TIME ZONE '".OrderQueues::TIMEZONE."' AS DATE) + i.lead_time_max_days < CAST(:today AS DATE))",
            // ready: it has active lines and every one of them has its goods
            OrderQueues::TO_SCHEDULE => "o.status = 'confirmed' AND EXISTS (SELECT 1 FROM shipment s WHERE s.order_id = o.id AND s.status = 'planned'
                AND EXISTS (SELECT 1 FROM order_item i WHERE i.shipment_id = s.id AND i.state = 'active')
                AND NOT EXISTS (SELECT 1 FROM order_item i WHERE i.shipment_id = s.id AND i.state = 'active' AND i.procurement_status NOT IN ('from_stock', 'received')))",
            OrderQueues::DELIVERING => $shipment('scheduled'),
            OrderQueues::UNPAID => $shipment('handed_over').' AND o.total_minor > '.self::NET_PAID,
            OrderQueues::REFUND => self::NET_PAID.' > o.total_minor',
        ];
    }

    /** One aggregate over every order. */
    public static function countsQuery(): string
    {
        $columns = [];
        foreach (self::conditions() as $queue => $condition) {
            $columns[] = "COUNT(*) FILTER (WHERE $condition) AS $queue";
        }

        return 'SELECT '.implode(', ', $columns).' FROM shop_order o';
    }

    /** Boolean columns q_<queue> for a select on shop_order o. */
    public static function flagColumns(): string
    {
        $columns = [];
        foreach (self::conditions() as $queue => $condition) {
            $columns[] = "($condition) AS q_$queue";
        }

        return implode(', ', $columns);
    }

    public static function today(\DateTimeImmutable $now): string
    {
        return OrderQueues::date($now)->format('Y-m-d');
    }
}
