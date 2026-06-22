<?php

declare(strict_types=1);

namespace App\Tests\Import;

/**
 * The Awin CSV fixture: every documented column, the shape the bike-components feed is expected in.
 * Rows are plain column => value maps so tests can override single cells or drop columns entirely.
 */
final class AwinFeed
{
    /** All documented columns; base_price, upc, commission_group and parent_product_id are ignored by the import. */
    public const COLUMNS = [
        'product_id', 'product_name', 'description', 'brand_name', 'ean', 'mpn', 'model_number',
        'colour', 'size', 'price', 'currency', 'rrp_price', 'stock_quantity', 'in_stock', 'stock_status',
        'number_available', 'delivery_time', 'delivery_cost', 'image_url', 'large_image', 'alternate_image',
        'merchant_category', 'merchant_product_category_path', 'deep_link', 'last_updated',
        'base_price', 'upc', 'commission_group', 'parent_product_id',
    ];

    /** One full row with every column filled. Overrides replace values; pass '' to blank one out. */
    public static function row(array $overrides = [], int $seed = 1): array
    {
        $id = 'BC-'.(1000 + $seed);
        $row = [
            'product_id' => $id,
            'product_name' => 'Shimano XT CS-M8100 cassette 12-speed',
            'description' => 'Lightweight 12-speed cassette for mountain bikes.',
            'brand_name' => 'Shimano',
            'ean' => self::ean($seed),
            'mpn' => 'CSM8100122',
            'model_number' => 'IMS8100',
            'colour' => '',
            'size' => '',
            'price' => '89.90',
            'currency' => 'EUR',
            'rrp_price' => '99.90',
            'stock_quantity' => '5',
            'in_stock' => 'yes',
            'stock_status' => 'In Stock',
            'number_available' => '7',
            'delivery_time' => '2-4 days',
            'delivery_cost' => '4.95',
            'image_url' => 'https://img.example/xt.jpg',
            'large_image' => 'https://img.example/xt_large.jpg',
            'alternate_image' => 'https://img.example/xt_alt.jpg',
            'merchant_category' => 'Cassettes',
            'merchant_product_category_path' => 'Components > Cassettes > 12-speed',
            'deep_link' => 'https://t.example/'.$id,
            'last_updated' => '2026-09-28 10:00:00',
            'base_price' => '79.00',
            'upc' => '',
            'commission_group' => 'Bike Parts',
            'parent_product_id' => 'BC-900-P',
        ];

        return [...$row, ...$overrides];
    }

    /** The fixture file: header plus the given rows, or the default three-product feed. */
    public static function csv(?array $rows = null, ?array $columns = null): string
    {
        $rows ??= [
            self::row(),
            self::row(['product_id' => 'BC-1002', 'product_name' => 'Schwalbe Marathon Supreme 40-622', 'ean' => self::ean(2), 'mpn' => 'MS40622', 'size' => '40-622', 'colour' => 'Black', 'price' => '44.90', 'rrp_price' => '', 'number_available' => '2', 'delivery_time' => '1-3 days', 'merchant_category' => 'Tyres', 'merchant_product_category_path' => 'Components > Tyres'], 2),
            self::row(['product_id' => 'BC-1003', 'product_name' => 'Magura MT5 brake pad pair', 'ean' => '', 'mpn' => 'MT5PAD', 'price' => '19.90', 'rrp_price' => '24.90', 'number_available' => '30', 'delivery_time' => '3-5 days', 'merchant_category' => 'Brake pads', 'merchant_product_category_path' => 'Components > Brake pads'], 3),
        ];
        $columns ??= self::COLUMNS;
        $lines = [implode(',', $columns)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                static fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
                array_map(static fn (string $column): string => $row[$column] ?? '', $columns),
            ));
        }

        return implode("\n", $lines)."\n";
    }

    public static function gzipped(string $csv): string
    {
        return (string) gzencode($csv);
    }

    /** A checksum-valid EAN-13 built from a small seed, so tests get distinct valid codes. */
    public static function ean(int $seed): string
    {
        $body = '40'.str_pad((string) (400000000 + $seed * 37), 10, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (array_reverse(str_split($body)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return $body.(string) ((10 - $sum % 10) % 10);
    }
}
