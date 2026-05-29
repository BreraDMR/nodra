<?php

declare(strict_types=1);

namespace App\Tests\Checkout;

use App\Entity\Product;
use App\Tests\Support\ApiTestCase;

final class CheckoutSourcingTest extends ApiTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->builder()->product('t-tyre', $this->builder()->category('t-tyres'));
    }

    public function testOrderKeepsTheSourcingOfTheMomentOfCheckout(): void
    {
        $b = $this->builder();
        $variant = $b->variant($this->product, 'T-TYRE-28');
        $offer = $b->pricedOffer($this->product, $variant, 2000, 'EUR', 25_000_000, 200, 2, 5);
        $id = $variant->getId()->toRfc4122();

        $receipt = $this->checkout([$id => 2], locale: 'en');
        self::assertResponseStatusCodeSame(201);
        // the customer's receipt has the lead time but no sourcing
        self::assertSame(['name', 'variant', 'sku', 'quantity', 'unitPrice', 'lineTotal', 'state', 'leadTimeMinDays', 'leadTimeMaxDays', 'shipment'], array_keys($receipt['items'][0]));
        self::assertSame([3, 6], [$receipt['items'][0]['leadTimeMinDays'], $receipt['items'][0]['leadTimeMaxDays']]);
        // nothing of NODRA's own is reserved for a line bought after confirmation
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM stock_movement WHERE variant_id = :id', ['id' => $id]));

        $token = $this->loginAdmin();
        $orderUri = '/api/admin/orders/'.$this->orderId($receipt['reference']);
        $snapshot = ['procurementStatus' => 'to_order', 'availabilityStatus' => 'orderable', 'leadTimeMinDays' => 3, 'leadTimeMaxDays' => 6, 'supplierOfferId' => $offer->getId()->toRfc4122(), 'unitCostCzkMinor' => 55000];
        self::assertSame($snapshot, array_intersect_key($this->getJson($orderUri)['items'][0], $snapshot));

        // the supplier's price and lead time change afterwards, and the listing is matched elsewhere
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$offer->getId()->toRfc4122(), [
            'supplier' => 'bike24', 'url' => 'https://supplier.example/moved', 'title' => 'Moved', 'currency' => 'CZK', 'priceMinor' => 99900,
            'checkedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'leadTimeMinDays' => 10, 'leadTimeMaxDays' => 20,
            'variantId' => $id, 'verificationStatus' => 'matched',
        ], $token);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', '/api/admin/variants/'.$id, [
            'sku' => 'T-TYRE-28', 'labelCs' => 'x', 'labelDe' => 'x', 'labelEn' => 'x', 'priceCzk' => 250000, 'priceEur' => 10000,
        ], $token);
        self::assertResponseIsSuccessful();

        $order = $this->getJson($orderUri);
        self::assertSame($snapshot, array_intersect_key($order['items'][0], $snapshot));
        // an English order is in CZK too: 1 000 Kč then
        self::assertSame([100000, 200000, 'CZK'], [$order['items'][0]['unitPrice'], $order['items'][0]['lineTotal'], $order['currency']]);
    }

    public function testUnavailableVariantIsRefusedAndNothingIsReserved(): void
    {
        $b = $this->builder();
        $gone = $b->variant($this->product, 'T-TYRE-NONE');
        $b->pricedOffer($this->product, $gone, 50000, reportedQuantity: 0);
        $held = $b->variant($this->product, 'T-TYRE-HELD', stock: 3);

        $error = $this->checkout([$gone->getId()->toRfc4122() => 1, $held->getId()->toRfc4122() => 1], locale: 'en');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['unavailable', $gone->getId()->toRfc4122()], [$error['code'], $error['variantId']]);
        self::assertSame('t-tyre en (T-TYRE-NONE en) cannot be ordered at the moment: no supplier has it available', $error['message']);
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT stock FROM product_variant WHERE id = :id', ['id' => $held->getId()->toRfc4122()]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM order_item WHERE variant_id IN (:a, :b)', ['a' => $gone->getId()->toRfc4122(), 'b' => $held->getId()->toRfc4122()]));
    }

    public function testVariantToConfirmCanStillBeOrdered(): void
    {
        $variant = $this->builder()->variant($this->product, 'T-TYRE-SNAP');
        $this->builder()->pricedOffer($this->product, null, 50000); // product-level snapshot

        $receipt = $this->checkout([$variant->getId()->toRfc4122() => 1]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame([null, null], [$receipt['shipments'][0]['leadTimeMinDays'], $receipt['shipments'][0]['leadTimeMaxDays']]);
        $this->loginAdmin();
        $item = $this->getJson('/api/admin/orders/'.$this->orderId($receipt['reference']))['items'][0];
        self::assertSame(['check_needed', null, null, null, null, 'to_order'], [$item['availabilityStatus'], $item['leadTimeMinDays'], $item['leadTimeMaxDays'], $item['supplierOfferId'], $item['unitCostCzkMinor'], $item['procurementStatus']]);
    }
}
