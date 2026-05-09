<?php

declare(strict_types=1);

namespace App\Tests\Pricing;

use App\Tests\Support\ApiTestCase;

final class PriceHistoryApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
        $this->builder()->category('t-lights');
    }

    public function testManualProductAndVariantEditsAreRecorded(): void
    {
        $created = $this->sendJson('POST', '/api/admin/products', $this->product(), $this->token);
        self::assertResponseStatusCodeSame(201);
        $base = $created['variantId'];

        // the product form edits the base variant's price
        $this->sendJson('PUT', '/api/admin/products/'.$created['id'], $this->product(['priceCzk' => 139000, 'priceEur' => 5600]), $this->token);
        self::assertResponseIsSuccessful();
        // saving it again unchanged adds nothing
        $this->sendJson('PUT', '/api/admin/products/'.$created['id'], $this->product(['priceCzk' => 139000, 'priceEur' => 5600]), $this->token);

        $history = $this->history($base);
        self::assertSame(2, $history['total']);
        self::assertSame([
            [129000, 139000, 5200, 5600, 'manual', 'test-admin@nodra.test'],
            [null, 129000, null, 5200, 'manual', 'test-admin@nodra.test'],
        ], array_map(self::row(...), $history['items']));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $history['items'][0]['changedAt']);

        $variant = $this->sendJson('POST', '/api/admin/products/'.$created['id'].'/variants', $this->variant(), $this->token);
        self::assertResponseStatusCodeSame(201);
        $this->sendJson('PUT', '/api/admin/variants/'.$variant['id'], $this->variant(['priceEur' => 3700]), $this->token);
        self::assertResponseIsSuccessful();
        // labels only, same prices
        $this->sendJson('PUT', '/api/admin/variants/'.$variant['id'], $this->variant(['priceEur' => 3700, 'labelEn' => 'Silver 2']), $this->token);
        self::assertResponseIsSuccessful();

        self::assertSame([
            [89000, 89000, 3600, 3700, 'manual', 'test-admin@nodra.test'],
            [null, 89000, null, 3600, 'manual', 'test-admin@nodra.test'],
        ], array_map(self::row(...), $this->history($variant['id'])['items']));
    }

    public function testHistoryIsPagedNewestFirst(): void
    {
        $product = $this->builder()->product('t-paged', $this->builder()->category('t-paged-cat'));
        $variant = $this->builder()->variant($product, 'T-PAGED')->getId()->toRfc4122();
        for ($i = 1; $i <= 31; ++$i) {
            $this->db()->insert('price_change', [
                'id' => sprintf('01890000-0000-7000-8000-%012d', $i), 'variant_id' => $variant,
                'old_price_czk' => $i * 100, 'new_price_czk' => ($i + 1) * 100, 'old_price_eur' => $i, 'new_price_eur' => $i + 1,
                'reason' => 'manual', 'changed_by' => 'someone', 'changed_at' => sprintf('2026-09-01 10:%02d:00+00', $i),
            ]);
        }

        $first = $this->history($variant);
        self::assertSame([1, 2, 31, 30], [$first['page'], $first['pages'], $first['total'], count($first['items'])]);
        self::assertSame(3200, $first['items'][0]['newPriceCzk']);
        $second = $this->history($variant, 2);
        self::assertSame([2, 1, 200], [$second['page'], count($second['items']), $second['items'][0]['newPriceCzk']]);
        // past the end gives the last page
        self::assertSame(2, $this->history($variant, 9)['page']);

        $this->getJson('/api/admin/variants/'.$variant.'/price-history', ['page' => 0]);
        self::assertResponseStatusCodeSame(422);
        $this->getJson('/api/admin/variants/01890000-0000-7000-8000-000000000000/price-history');
        self::assertResponseStatusCodeSame(404);
    }

    public function testVariantKeepsRrpAndMarketPriceForTheAdminOnly(): void
    {
        $created = $this->sendJson('POST', '/api/admin/products', $this->product(), $this->token);
        $reference = [
            'rrpMinor' => 2800, 'rrpCurrency' => 'EUR', 'rrpSource' => ' https://maker.example/x ', 'rrpCheckedAt' => '2026-09-20',
            'marketPriceMinor' => 99000, 'marketPriceSource' => 'Heureka', 'marketCheckedAt' => '2026-09-27',
        ];
        $variant = $this->sendJson('POST', '/api/admin/products/'.$created['id'].'/variants', $this->variant($reference), $this->token);
        self::assertResponseStatusCodeSame(201);

        $expected = ['rrpSource' => 'https://maker.example/x'] + $reference;
        $stored = array_intersect_key($this->adminVariant('T-LAMP-SILVER'), $reference);
        ksort($expected);
        ksort($stored);
        self::assertSame($expected, $stored);
        self::assertSame(70000, $this->getJson('/api/admin/variants/'.$variant['id'].'/pricing')['rrpCzk']);

        // RRP needs its currency, dates must be dates
        $this->sendJson('PUT', '/api/admin/variants/'.$variant['id'], $this->variant(['rrpMinor' => 2800]), $this->token);
        self::assertResponseStatusCodeSame(422);
        $error = $this->sendJson('PUT', '/api/admin/variants/'.$variant['id'], $this->variant(['rrpMinor' => 2800, 'rrpCurrency' => 'PLN', 'marketCheckedAt' => 'yesterday']), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['rrpCurrency', 'marketCheckedAt'], array_column($error['violations'], 'field'));

        // PUT replaces the record: leaving them out clears them
        $this->sendJson('PUT', '/api/admin/variants/'.$variant['id'], $this->variant(), $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame([null, null, null, null], [$this->adminVariant('T-LAMP-SILVER')['rrpMinor'], $this->adminVariant('T-LAMP-SILVER')['rrpSource'],
            $this->adminVariant('T-LAMP-SILVER')['marketPriceMinor'], $this->adminVariant('T-LAMP-SILVER')['marketCheckedAt']]);
    }

    public function testOfferTakesInboundShippingAndExchangeRate(): void
    {
        $product = $this->builder()->product('t-lamp', $this->builder()->category('t-lamps'));
        $uri = '/api/admin/products/'.$product->getId()->toRfc4122().'/supplier-offers';
        $offer = [
            'supplier' => 'bike24', 'url' => 'https://bike24.example/lamp', 'title' => 'Lamp', 'currency' => 'EUR', 'priceMinor' => 4999,
            'checkedAt' => '2026-09-26T10:00:00+02:00', 'inboundShippingMinor' => 499, 'fxRateCzk' => 25_315_000, 'fxRateDate' => '2026-09-26',
        ];

        $created = $this->sendJson('POST', $uri, $offer, $this->token);
        self::assertResponseStatusCodeSame(201);
        $stored = $this->db()->fetchAssociative('SELECT inbound_shipping_minor, fx_rate_czk, fx_rate_date FROM supplier_offer WHERE id = :id', ['id' => $created['id']]);
        self::assertSame([499, 25_315_000, '2026-09-26'], [(int) $stored['inbound_shipping_minor'], (int) $stored['fx_rate_czk'], $stored['fx_rate_date']]);

        // a CZK offer is always 1:1, an unknown EUR rate stays unknown
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$created['id'], ['currency' => 'CZK', 'fxRateCzk' => 2_000_000] + $offer, $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$created['id'], ['currency' => 'CZK', 'fxRateCzk' => null, 'priceMinor' => 129900] + $offer, $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(1_000_000, (int) $this->db()->fetchOne('SELECT fx_rate_czk FROM supplier_offer WHERE id = :id', ['id' => $created['id']]));
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$created['id'], ['fxRateCzk' => null, 'fxRateDate' => null] + $offer, $this->token);
        self::assertResponseIsSuccessful();
        self::assertNull($this->db()->fetchOne('SELECT fx_rate_czk FROM supplier_offer WHERE id = :id', ['id' => $created['id']]));

        $error = $this->sendJson('POST', $uri, ['inboundShippingMinor' => -1, 'fxRateCzk' => 0, 'fxRateDate' => '26.9.2026', 'url' => 'https://bike24.example/other'] + $offer, $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['inboundShippingMinor', 'fxRateCzk', 'fxRateDate'], array_column($error['violations'], 'field'));
    }

    private function history(string $variantId, int $page = 1): array
    {
        $history = $this->getJson('/api/admin/variants/'.$variantId.'/price-history', ['page' => $page]);
        self::assertResponseIsSuccessful();

        return $history;
    }

    private static function row(array $item): array
    {
        return [$item['oldPriceCzk'], $item['newPriceCzk'], $item['oldPriceEur'], $item['newPriceEur'], $item['reason'], $item['changedBy']];
    }

    private function adminVariant(string $sku): array
    {
        foreach ($this->getJson('/api/admin/products', ['q' => 't-lamp'])['items'] as $product) {
            foreach ($product['variants'] as $variant) {
                if ($variant['sku'] === $sku) {
                    return $variant;
                }
            }
        }
        self::fail('No variant '.$sku);
    }

    private function product(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 't-lamp', 'category' => 't-lights', 'status' => 'published',
            'nameCs' => 'Lampa', 'nameDe' => 'Lampe', 'nameEn' => 'Lamp', 'shortCs' => 'Krátký', 'shortDe' => 'Kurz', 'shortEn' => 'Short',
            'image' => '/images/lamp.png', 'priceCzk' => 129000, 'priceEur' => 5200,
        ];
    }

    private function variant(array $overrides = []): array
    {
        return $overrides + ['sku' => 'T-LAMP-SILVER', 'labelCs' => 'Stříbrná', 'labelDe' => 'Silber', 'labelEn' => 'Silver', 'priceCzk' => 89000, 'priceEur' => 3600];
    }
}
