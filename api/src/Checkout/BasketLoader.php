<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Order\OrderProblem;
use App\Pricing\AvailabilityService;
use App\Pricing\SourcingCalculator;
use Doctrine\DBAL\Connection;

final class BasketLoader
{
    public function __construct(private Connection $db, private AvailabilityService $availability, private SourcingCalculator $calculator) {}

    /**
     * @param array<string, int> $quantities variant id => quantity, sorted by id so row locks are always taken in the same order
     * @param bool $lock locks the variant rows until the transaction ends; call it inside one
     * @param bool $publishedOnly false lets the admin pick an active variant of a draft product
     *
     * @return list<BasketLine>
     */
    public function lines(array $quantities, string $locale, bool $lock = false, bool $publishedOnly = true): array
    {
        $rows = [];
        foreach (array_keys($quantities) as $id) {
            $row = $this->db->fetchAssociative('SELECT v.id, v.product_id, v.sku, v.stock, v.active, v.price_czk, v.label, p.status, p.copy
                FROM product_variant v JOIN product p ON p.id = v.product_id WHERE v.id = :id'.($lock ? ' FOR UPDATE OF v' : ''), ['id' => $id]);
            if ($row === false || !$row['active'] || ($publishedOnly && $row['status'] !== 'published')) {
                throw OrderProblem::unprocessable('product_unavailable', 'A selected product is no longer available', ['variantId' => (string) $id]);
            }
            $rows[$id] = $row;
        }
        // the same calculation the catalogue shows; the order keeps what it said at this moment
        $sourcing = $this->availability->forVariants(array_column($rows, 'product_id', 'id'));

        $lines = [];
        foreach ($quantities as $id => $quantity) {
            $row = $rows[$id];
            $stock = (int) $row['stock'];

            $lines[] = new BasketLine(
                (string) $id,
                json_decode($row['copy'], true, flags: JSON_THROW_ON_ERROR)[$locale]['name'],
                json_decode($row['label'], true, flags: JSON_THROW_ON_ERROR)[$locale],
                $row['sku'],
                $quantity,
                (int) $row['price_czk'],
                $stock >= $quantity,
                $this->calculator->withOwnStock($sourcing[$id], $stock, $quantity),
            );
        }

        return $lines;
    }
}
