<?php

declare(strict_types=1);

namespace App\Purchase;

use App\Admin\AdminPurchasesQuery;
use App\Order\OrderQueues;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/** Admin reads for buying: what's left to buy by supplier, and the purchases. A fixed number of queries each. */
final class PurchaseQueries
{
    public function __construct(private Connection $db) {}

    /**
     * `to_order` lines of confirmed orders grouped by the supplier of their checkout offer, suppliers by name and the
     * lines without an offer last (supplier null). Oldest confirmation first inside a group.
     */
    public function toPurchase(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.order_id, o.reference, o.confirmed_at, o.currency AS order_currency, i.variant_id, i.product_name, i.variant_label, i.sku, i.quantity,
                i.unit_price_minor, i.unit_cost_czk_minor, i.lead_time_max_days,
                f.id AS offer_id, f.supplier, f.seller, f.url, f.currency, f.price_minor, f.inbound_shipping_minor, f.fx_rate_czk, f.fx_rate_date
            FROM order_item i JOIN shop_order o ON o.id = i.order_id LEFT JOIN supplier_offer f ON f.id = i.supplier_offer_id
            WHERE o.status = 'confirmed' AND i.state = 'active' AND i.procurement_status = 'to_order'
            ORDER BY f.supplier ASC NULLS LAST, o.confirmed_at ASC NULLS FIRST, o.created_at, i.id",
        );
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['supplier'] ?? '';
            $groups[$key] ??= ['supplier' => $row['supplier'], 'lines' => []];
            $confirmedAt = $row['confirmed_at'] === null ? null : new \DateTimeImmutable($row['confirmed_at']);
            $groups[$key]['lines'][] = [
                'itemId' => $row['id'], 'orderId' => $row['order_id'], 'orderReference' => $row['reference'],
                'confirmedAt' => $confirmedAt?->format(\DATE_ATOM),
                'variantId' => $row['variant_id'], 'name' => $row['product_name'], 'variant' => $row['variant_label'], 'sku' => $row['sku'],
                'quantity' => (int) $row['quantity'],
                'unitPrice' => ['amount' => (int) $row['unit_price_minor'], 'currency' => $row['order_currency']],
                'snapshotUnitCostCzkMinor' => self::int($row['unit_cost_czk_minor']),
                'leadTimeMaxDays' => self::int($row['lead_time_max_days']),
                'promisedDate' => $confirmedAt === null || $row['lead_time_max_days'] === null ? null
                    : OrderQueues::date($confirmedAt)->modify(sprintf('+%d days', $row['lead_time_max_days']))->format('Y-m-d'),
                'offer' => $row['offer_id'] === null ? null : [
                    'id' => $row['offer_id'], 'supplier' => $row['supplier'], 'seller' => $row['seller'], 'url' => $row['url'],
                    'currency' => $row['currency'], 'priceMinor' => (int) $row['price_minor'], 'inboundShippingMinor' => (int) $row['inbound_shipping_minor'],
                    'fxRateCzk' => self::int($row['fx_rate_czk']), 'fxRateDate' => $row['fx_rate_date'],
                ],
            ];
        }

        return ['groups' => array_values($groups), 'lineCount' => count($rows)];
    }

    public function list(AdminPurchasesQuery $query): array
    {
        $filter = $query->status === null ? '' : ' WHERE p.status = :status';
        $params = $query->status === null ? [] : ['status' => $query->status];
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase p'.$filter, $params);
        $pages = max(1, (int) ceil($total / 30));
        $page = min($query->page, $pages);
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.supplier, p.seller, p.reference, p.currency, p.status, p.ordered_at, p.received_at, p.inbound_shipping_minor,
                COUNT(l.id) AS line_count, COALESCE(SUM(l.unit_price_minor * l.quantity), 0) AS goods_minor,
                COALESCE(SUM(l.unit_cost_czk_minor * l.quantity), 0) AS cost_czk_minor
            FROM purchase p LEFT JOIN purchase_line l ON l.purchase_id = p.id'.$filter.'
            GROUP BY p.id ORDER BY p.ordered_at DESC, p.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => 30, 'offset' => ($page - 1) * 30],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => $row['id'], 'supplier' => $row['supplier'], 'seller' => $row['seller'], 'reference' => $row['reference'],
                'currency' => $row['currency'], 'status' => $row['status'],
                'orderedAt' => self::time($row['ordered_at']), 'receivedAt' => self::time($row['received_at']),
                'lineCount' => (int) $row['line_count'], 'goodsMinor' => (int) $row['goods_minor'],
                'inboundShippingMinor' => (int) $row['inbound_shipping_minor'], 'costCzkMinor' => (int) $row['cost_czk_minor'],
            ], $rows),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    public function detail(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $purchase = $this->db->fetchAssociative('SELECT * FROM purchase WHERE id = :id', ['id' => strtolower($id)]);
        if ($purchase === false) {
            return null;
        }
        $lines = $this->db->fetchAllAssociative(
            'SELECT l.id, l.order_item_id, l.quantity, l.unit_price_minor, l.allocated_shipping_minor, l.unit_cost_czk_minor,
                i.order_id, o.reference, i.sku, i.product_name, i.variant_label, i.unit_cost_czk_minor AS snapshot_cost, i.procurement_status, i.state
            FROM purchase_line l JOIN order_item i ON i.id = l.order_item_id JOIN shop_order o ON o.id = i.order_id
            WHERE l.purchase_id = :id ORDER BY o.reference, i.sku, l.id',
            ['id' => $purchase['id']],
        );
        $ordered = $purchase['status'] === PurchaseStatus::ORDERED;

        return [
            'id' => $purchase['id'], 'supplier' => $purchase['supplier'], 'seller' => $purchase['seller'], 'reference' => $purchase['reference'],
            'currency' => $purchase['currency'], 'fxRateCzk' => (int) $purchase['fx_rate_czk'], 'fxRateDate' => $purchase['fx_rate_date'],
            'inboundShippingMinor' => (int) $purchase['inbound_shipping_minor'], 'status' => $purchase['status'],
            'orderedAt' => self::time($purchase['ordered_at']), 'receivedAt' => self::time($purchase['received_at']),
            'cancelledAt' => self::time($purchase['cancelled_at']), 'cancelReason' => $purchase['cancel_reason'],
            'note' => $purchase['note'], 'createdBy' => $purchase['created_by'],
            'goodsMinor' => array_sum(array_map(static fn (array $l): int => (int) $l['unit_price_minor'] * (int) $l['quantity'], $lines)),
            'costCzkMinor' => array_sum(array_map(static fn (array $l): int => (int) $l['unit_cost_czk_minor'] * (int) $l['quantity'], $lines)),
            'lines' => array_map(static fn (array $l): array => [
                'id' => $l['id'], 'itemId' => $l['order_item_id'], 'orderId' => $l['order_id'], 'orderReference' => $l['reference'],
                'sku' => $l['sku'], 'name' => $l['product_name'], 'variant' => $l['variant_label'], 'quantity' => (int) $l['quantity'],
                'unitPriceMinor' => (int) $l['unit_price_minor'], 'allocatedShippingMinor' => (int) $l['allocated_shipping_minor'],
                'unitCostCzkMinor' => (int) $l['unit_cost_czk_minor'], 'snapshotUnitCostCzkMinor' => self::int($l['snapshot_cost']),
                'procurementStatus' => $l['procurement_status'], 'lineState' => $l['state'],
            ], $lines),
            'actions' => $ordered ? ['receive', 'cancel'] : [],
        ];
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function time(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value))->format(\DATE_ATOM);
    }
}
