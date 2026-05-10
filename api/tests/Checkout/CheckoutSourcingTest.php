<?php

declare(strict_types=1);

namespace App\Tests\Checkout;

use App\Entity\Product;
use App\Entity\ProductVariant;
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
        $variant = $b->variant($this->product, 'T-TYRE-28', stock: 3);
        $offer = $b->pricedOffer($this->product, $variant, 2000, 'EUR', 25_000_000, 200, 2, 5);

        $receipt = $this->checkout($variant, 2);
        self::assertResponseStatusCodeSame(201);
        // the customer's receipt has no sourcing
        self::assertSame(['name', 'variant', 'sku', 'quantity', 'unitPrice', 'lineTotal'], array_keys($receipt['items'][0]));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT stock FROM product_variant WHERE id = :id', ['id' => $variant->getId()->toRfc4122()]));

        $token = $this->loginAdmin();
        $orderUri = '/api/admin/orders/'.$this->db()->fetchOne('SELECT id FROM shop_order WHERE reference = :ref', ['ref' => $receipt['reference']]);
        $snapshot = ['availabilityStatus' => 'orderable', 'leadTimeMinDays' => 3, 'leadTimeMaxDays' => 6, 'supplierOfferId' => $offer->getId()->toRfc4122(), 'unitCostCzkMinor' => 55000];
        self::assertSame($snapshot, array_intersect_key($this->getJson($orderUri)['items'][0], $snapshot));

        // the supplier's price and lead time change afterwards, and the listing is matched elsewhere
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$offer->getId()->toRfc4122(), [
            'supplier' => 'bike24', 'url' => 'https://supplier.example/moved', 'title' => 'Moved', 'currency' => 'CZK', 'priceMinor' => 99900,
            'checkedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'leadTimeMinDays' => 10, 'leadTimeMaxDays' => 20,
            'variantId' => $variant->getId()->toRfc4122(), 'verificationStatus' => 'matched',
        ], $token);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', '/api/admin/variants/'.$variant->getId()->toRfc4122(), [
            'sku' => 'T-TYRE-28', 'labelCs' => 'x', 'labelDe' => 'x', 'labelEn' => 'x', 'priceCzk' => 250000, 'priceEur' => 10000,
        ], $token);
        self::assertResponseIsSuccessful();

        $order = $this->getJson($orderUri);
        self::assertSame($snapshot, array_intersect_key($order['items'][0], $snapshot));
        self::assertSame([4000, 8000], [$order['items'][0]['unitPrice'], $order['items'][0]['lineTotal']]); // EUR order, 40 € then
    }

    public function testUnavailableVariantIsRefusedAndNothingIsReserved(): void
    {
        $variant = $this->builder()->variant($this->product, 'T-TYRE-NONE', stock: 3);
        $this->builder()->pricedOffer($this->product, $variant, 50000, reportedQuantity: 0);

        $error = $this->checkout($variant, 1);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('t-tyre en (T-TYRE-NONE en) cannot be ordered at the moment: no supplier has it available', $error['message']);
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT stock FROM product_variant WHERE id = :id', ['id' => $variant->getId()->toRfc4122()]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM order_item WHERE variant_id = :id', ['id' => $variant->getId()->toRfc4122()]));
    }

    public function testVariantToConfirmCanStillBeOrdered(): void
    {
        $variant = $this->builder()->variant($this->product, 'T-TYRE-SNAP');
        $this->builder()->pricedOffer($this->product, null, 50000); // product-level snapshot

        $receipt = $this->checkout($variant, 1);

        self::assertResponseStatusCodeSame(201);
        $this->loginAdmin();
        $item = $this->getJson('/api/admin/orders/'.$this->db()->fetchOne('SELECT id FROM shop_order WHERE reference = :ref', ['ref' => $receipt['reference']]))['items'][0];
        self::assertSame(['check_needed', null, null, null, null], [$item['availabilityStatus'], $item['leadTimeMinDays'], $item['leadTimeMaxDays'], $item['supplierOfferId'], $item['unitCostCzkMinor']]);
    }

    private function checkout(ProductVariant $variant, int $quantity): array
    {
        $this->client->request('POST', '/api/checkout', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12)),
        ], content: json_encode([
            'locale' => 'en',
            'customer' => ['name' => 'Rider', 'email' => 'rider@example.test', 'country' => 'CZ', 'address' => 'Demo 1', 'postalCode' => '110 00', 'district' => 'Praha 1'],
            'items' => [['variantId' => $variant->getId()->toRfc4122(), 'quantity' => $quantity]],
        ], JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
