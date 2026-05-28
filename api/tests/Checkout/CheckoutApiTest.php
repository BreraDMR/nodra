<?php

declare(strict_types=1);

namespace App\Tests\Checkout;

use App\Entity\CustomerAccount;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CheckoutApiTest extends ApiTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->builder()->product('t-order', $this->builder()->category('t-order-cat'));
    }

    public function testOrderRequestKeepsContactConsentAndDeliveryInCzk(): void
    {
        $item = $this->offered('T-O-REQ', 30000);

        $receipt = $this->checkout([$item => 2], customer: ['deliveryNote' => 'Ring twice'], locale: 'de', consents: ['privacy' => true, 'marketing' => true]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['requested', 'unpaid', 'together', 'CZK'], [$receipt['status'], $receipt['paymentStatus'], $receipt['fulfilment'], $receipt['total']['currency']]);
        self::assertSame([60000, 0, 60000, 60000], [$receipt['subtotal']['amount'], $receipt['shipping']['amount'], $receipt['total']['amount'], $receipt['amountDue']['amount']]);
        self::assertSame([['number' => 1, 'method' => 'prague_personal', 'status' => 'planned']], array_map(static fn (array $s): array => array_intersect_key($s, array_flip(['number', 'method', 'status'])), $receipt['shipments']));
        $row = $this->db()->fetchAssociative('SELECT phone, contact_channel, city, postal_code, delivery_note, privacy_text_version, privacy_consented_at, marketing_consent, marketing_consented_at, currency FROM shop_order WHERE reference = :ref', ['ref' => $receipt['reference']]);
        self::assertSame(['+420777123456', 'whatsapp', 'Praha', '120 00', 'Ring twice', 'draft-2026-09', true, 'CZK'], [$row['phone'], $row['contact_channel'], $row['city'], $row['postal_code'], $row['delivery_note'], $row['privacy_text_version'], $row['marketing_consent'], $row['currency']]);
        self::assertNotNull($row['privacy_consented_at']);
        self::assertNotNull($row['marketing_consented_at']);
        self::assertSame(['placed', 'customer'], array_values($this->db()->fetchAssociative('SELECT type, actor FROM order_event WHERE order_id = :id', ['id' => $this->orderId($receipt['reference'])])));

        $lookup = $this->getJson('/api/orders/'.$receipt['reference'], ['token' => $receipt['lookupToken']]);
        self::assertSame($receipt, $lookup);
        $this->getJson('/api/orders/'.$receipt['reference'], ['token' => 'wrong']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPhoneAndConsentAreRequired(): void
    {
        $item = [$this->offered('T-O-CONSENT', 30000) => 1];

        $error = $this->checkout($item, customer: ['phone' => '12345']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['invalid_field', 'customer.phone'], [$error['code'], $error['violations'][0]['field']]);

        $error = $this->checkout($item, consents: ['privacy' => false]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('consents[privacy]', $error['violations'][0]['field']);
        $this->checkout($item, consents: ['marketing' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM shop_order'));
    }

    public function testChangedTotalIsA409WithTheFreshQuoteAndWritesNothing(): void
    {
        $item = [$this->offered('T-O-CHANGED', 30000) => 1];
        $shown = $this->quote($item)['options']['together']['total']['amount'];
        // the price went up after the customer looked
        $this->db()->executeStatement("UPDATE product_variant SET price_czk = 35000 WHERE sku = 'T-O-CHANGED'");

        $error = $this->checkout($item, expectedTotal: $shown);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['quote_changed', 49900], [$error['code'], $error['quote']['options']['together']['total']['amount']]);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM shop_order'));
        $this->checkout($item, expectedTotal: 49900);
        self::assertResponseStatusCodeSame(201);
    }

    public function testSameIdempotencyKeyTwiceGivesOneOrderAndAnotherBodyConflicts(): void
    {
        $item = [$this->held('T-O-IDEM', 30000, stock: 3) => 1];
        $key = bin2hex(random_bytes(12));

        $first = $this->checkout($item, key: $key);
        self::assertResponseStatusCodeSame(201);
        $again = $this->checkout($item, key: $key);
        self::assertResponseStatusCodeSame(201);

        self::assertSame($first['reference'], $again['reference']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM shop_order'));
        // stock reserved once
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT stock FROM product_variant WHERE sku = 'T-O-IDEM'"));

        $error = $this->checkout($item, customer: ['phone' => '777 000 111'], key: $key);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('idempotency_conflict', $error['code']);
    }

    public function testOwnStockIsReservedOnlyForLinesItCovers(): void
    {
        $covered = $this->held('T-O-COVERED', 30000, stock: 2);
        $short = $this->held('T-O-SHORT', 30000, stock: 1);
        $this->builder()->pricedOffer($this->product, $this->variant('T-O-SHORT'), 20000);
        $none = $this->offered('T-O-NONE', 30000);

        $receipt = $this->checkout([$covered => 1, $short => 2, $none => 1], fulfilment: 'split');

        self::assertResponseStatusCodeSame(201);
        $stock = $this->db()->fetchAllKeyValue("SELECT sku, stock FROM product_variant WHERE sku LIKE 'T-O-%' ORDER BY sku");
        self::assertSame(['T-O-COVERED' => 1, 'T-O-NONE' => 0, 'T-O-SHORT' => 1], array_map('intval', $stock));
        $orderId = $this->orderId($receipt['reference']);
        $movements = $this->db()->fetchAllAssociative('SELECT v.sku, m.delta, m.reason FROM stock_movement m JOIN product_variant v ON v.id = m.variant_id WHERE m.order_id = :id', ['id' => $orderId]);
        self::assertSame([['sku' => 'T-O-COVERED', 'delta' => -1, 'reason' => 'Order '.$receipt['reference'].': reserved at checkout']], $movements);
        self::assertSame(['T-O-COVERED' => 'from_stock', 'T-O-NONE' => 'to_order', 'T-O-SHORT' => 'to_order'], $this->db()->fetchAllKeyValue('SELECT sku, procurement_status FROM order_item WHERE order_id = :id ORDER BY sku', ['id' => $orderId]));
        // the stock line waits only for handling, the others for the supplier
        self::assertSame([[1, 1], [3, 6]], array_map(static fn (array $s): array => [$s['leadTimeMinDays'], $s['leadTimeMaxDays']], $receipt['shipments']));
    }

    public function testMethodSplitAndCarrierAreRefusedWhenTheyCantBeDelivered(): void
    {
        $item = [$this->offered('T-O-METHOD', 30000) => 1];

        $outside = $this->checkout($item, customer: ['postalCode' => '602 00', 'city' => 'Brno'], expectedTotal: 30000);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['method_unavailable', 'outside_prague'], [$outside['code'], $outside['reason']]);

        $carrier = $this->checkout($item, 'carrier_cz', customer: ['postalCode' => '602 00', 'city' => 'Brno'], expectedTotal: 30000);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['method_unavailable', 'not_offered'], [$carrier['code'], $carrier['reason']]);

        $split = $this->checkout($item, fulfilment: 'split', expectedTotal: 44900);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('split_unavailable', $split['code']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM shop_order'));
    }

    public function testPickupIgnoresTheAddressAndCheckNeededIsAccepted(): void
    {
        $snapshot = $this->variant('T-O-SNAP');
        $this->builder()->pricedOffer($this->product, null, 30000);

        $receipt = $this->checkout([$snapshot->getId()->toRfc4122() => 1], 'pickup_andel', customer: ['address' => '', 'city' => '', 'postalCode' => '']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['pickup_andel', 0, 'Anděl, place and time agreed by message'], [$receipt['shipments'][0]['method'], $receipt['shipping']['amount'], $receipt['pickupNote']]);
        self::assertSame(['', '', ''], array_values($this->db()->fetchAssociative('SELECT address, city, postal_code FROM shop_order WHERE reference = :ref', ['ref' => $receipt['reference']])));
    }

    public function testSignedInCustomerGetsTheLastDeliveryForPrefill(): void
    {
        $first = $this->offered('T-O-PREFILL', 30000);
        $second = $this->offered('T-O-PREFILL-2', 30000);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $account = new CustomerAccount('google-prefill', 'rider@example.test', 'Rider');
        $em->persist($account);
        $em->flush();
        $this->signInCustomer($account);
        self::assertNull($this->getJson('/api/account/me')['lastDelivery']);

        $this->checkout([$first => 1], customer: ['contactChannel' => 'telegram', 'district' => '']);
        self::assertResponseStatusCodeSame(201);

        $me = $this->getJson('/api/account/me');
        self::assertSame(['phone' => '+420777123456', 'contactChannel' => 'telegram', 'address' => 'Vinohradská 1', 'city' => 'Praha', 'postalCode' => '120 00', 'district' => null], $me['lastDelivery']);

        // someone else's email doesn't collect this account's points
        $error = $this->checkout([$second => 1], customer: ['email' => 'other@example.test']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('customer.email', $error['violations'][0]['field']);
    }

    private function variant(string $sku, int $priceCzk = 30000, int $stock = 0): ProductVariant
    {
        $existing = static::getContainer()->get(EntityManagerInterface::class)->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);

        return $existing ?? $this->builder()->variant($this->product, $sku, stock: $stock, priceCzk: $priceCzk);
    }

    private function offered(string $sku, int $priceCzk): string
    {
        $variant = $this->variant($sku, $priceCzk);
        $this->builder()->pricedOffer($this->product, $variant, 20000);

        return $variant->getId()->toRfc4122();
    }

    private function held(string $sku, int $priceCzk, int $stock): string
    {
        return $this->variant($sku, $priceCzk, $stock)->getId()->toRfc4122();
    }
}
