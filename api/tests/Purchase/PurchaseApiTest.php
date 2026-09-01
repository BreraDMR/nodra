<?php

declare(strict_types=1);

namespace App\Tests\Purchase;

use App\Tests\Support\AdminOrderSteps;
use App\Tests\Support\ApiTestCase;

/** Buying by supplier through the admin API: what's to buy, recording, receiving and cancelling a purchase. */
final class PurchaseApiTest extends ApiTestCase
{
    use AdminOrderSteps;

    private const RATE = 25_315_000;

    public function testLinesToBuyAreGroupedBySupplierWithTheNoSourceGroupLast(): void
    {
        $this->token = $this->loginAdmin();
        $first = $this->placeOrder([$this->offered('T-P-G-B24', 30000) => 1, $this->offered('T-P-G-INN', 30000, 'bikeinn', 15000) => 2, $this->snapshotOnly('T-P-G-NONE') => 1]);
        $second = $this->placeOrder([$this->offered('T-P-G-B24-2', 30000) => 1, $this->held('T-P-G-HELD', 30000) => 1]);
        $requested = $this->placeOrder([$this->offered('T-P-G-LATER', 30000) => 1]);
        $this->act($first, 'confirm', self::AGREED);
        $this->act($second, 'confirm', self::AGREED);

        $toBuy = $this->getJson('/api/admin/to-purchase');

        self::assertSame(['bike24', 'bikeinn', null], array_column($toBuy['groups'], 'supplier'));
        self::assertSame(4, $toBuy['lineCount']);
        self::assertSame([['T-P-G-B24', 'T-P-G-B24-2'], ['T-P-G-INN'], ['T-P-G-NONE']], array_map(static fn (array $g): array => array_column($g['lines'], 'sku'), $toBuy['groups']));
        $line = $toBuy['groups'][1]['lines'][0];
        self::assertSame([$first, 2, 15000, 'CZK', 'bikeinn', 'Secret Seller GmbH', 15000], [
            $line['orderId'], $line['quantity'], $line['snapshotUnitCostCzkMinor'], $line['offer']['currency'], $line['offer']['supplier'], $line['offer']['seller'], $line['offer']['priceMinor'],
        ]);
        self::assertStringStartsWith('https://supplier.example/', $line['offer']['url']);
        self::assertSame(['amount' => 30000, 'currency' => 'CZK'], $line['unitPrice']);
        self::assertNull($toBuy['groups'][2]['lines'][0]['offer']);
        self::assertNotContains($requested, array_merge(...array_map(static fn (array $g): array => array_column($g['lines'], 'orderId'), $toBuy['groups'])), 'not confirmed yet');
    }

    public function testEurPurchaseSplitsShippingAndGivesTheLinesTheirActualCost(): void
    {
        $this->token = $this->loginAdmin();
        $a = $this->offered('T-P-EUR-A', 90000, offerPrice: 2000, currency: 'EUR', fxRateCzk: 25_000_000);
        $b = $this->offered('T-P-EUR-B', 90000, offerPrice: 3000, currency: 'EUR', fxRateCzk: 25_000_000);
        $c = $this->offered('T-P-EUR-C', 90000, offerPrice: 1000, currency: 'EUR', fxRateCzk: 25_000_000);
        $first = $this->placeOrder([$a => 1, $b => 2]);
        $second = $this->placeOrder([$c => 1]);
        foreach ([$first, $second] as $id) {
            $this->act($id, 'confirm', self::AGREED);
        }
        $items = [
            'A' => $this->line($this->order($first), 'T-P-EUR-A')['id'],
            'B' => $this->line($this->order($first), 'T-P-EUR-B')['id'],
            'C' => $this->order($second)['items'][0]['id'],
        ];
        $body = [
            'supplier' => 'bike24', 'seller' => 'Bike24 GmbH', 'reference' => 'B24-9001', 'currency' => 'EUR', 'fxRateCzk' => self::RATE, 'fxRateDate' => '2026-09-28',
            'inboundShippingMinor' => 1000, 'note' => 'One parcel',
            'lines' => [['itemId' => $items['C'], 'unitPriceMinor' => 1000], ['itemId' => $items['A'], 'unitPriceMinor' => 2000], ['itemId' => $items['B'], 'unitPriceMinor' => 3000]],
        ];
        $key = bin2hex(random_bytes(12));

        $purchase = $this->purchase($body, $key);

        // values 2000, 6000, 1000: 222, 666, 111 and the 1 left goes to the largest line
        $lines = array_column($purchase['lines'], null, 'sku');
        self::assertSame([[222, 56250], [667, 84388], [111, 28125]], [
            [$lines['T-P-EUR-A']['allocatedShippingMinor'], $lines['T-P-EUR-A']['unitCostCzkMinor']],
            [$lines['T-P-EUR-B']['allocatedShippingMinor'], $lines['T-P-EUR-B']['unitCostCzkMinor']],
            [$lines['T-P-EUR-C']['allocatedShippingMinor'], $lines['T-P-EUR-C']['unitCostCzkMinor']],
        ]);
        self::assertSame(['ordered', 'bike24', 'Bike24 GmbH', 'B24-9001', 'EUR', self::RATE, '2026-09-28', 1000, 9000, 56250 + 2 * 84388 + 28125, ['receive', 'cancel'], 'test-admin@nodra.test'], [
            $purchase['status'], $purchase['supplier'], $purchase['seller'], $purchase['reference'], $purchase['currency'], $purchase['fxRateCzk'], $purchase['fxRateDate'],
            $purchase['inboundShippingMinor'], $purchase['goodsMinor'], $purchase['costCzkMinor'], $purchase['actions'], $purchase['createdBy'],
        ]);
        self::assertSame([2, 50000], [$lines['T-P-EUR-B']['quantity'], $lines['T-P-EUR-A']['snapshotUnitCostCzkMinor']]);

        $order = $this->order($first);
        $bought = $this->line($order, 'T-P-EUR-B');
        self::assertSame(['ordered', 'B24-9001', 84388, 75000, ['id' => $purchase['id'], 'reference' => 'B24-9001', 'status' => 'ordered']], [
            $bought['procurementStatus'], $bought['supplierReference'], $bought['actualUnitCostCzkMinor'], $bought['unitCostCzkMinor'], $bought['purchase'],
        ]);
        $event = array_values(array_filter($order['events'], static fn (array $e): bool => $e['type'] === 'item_ordered' && $e['data']['itemId'] === $items['B']))[0];
        self::assertSame([$purchase['id'], 'B24-9001', 'test-admin@nodra.test'], [$event['data']['purchaseId'], $event['data']['supplierReference'], $event['actor']]);
        // the order earns 270000 - (56250 + 2 x 84388), every line has its actual cost now
        self::assertSame([270000, 225026, 44974, true], [$order['economics']['goodsRevenueMinor'], $order['economics']['costMinor'], $order['economics']['expectedResultMinor'], $order['economics']['costComplete']]);
        self::assertSame(0, $this->getJson('/api/admin/to-purchase')['lineCount']);

        // the same key and body again is the same purchase, another body with it is refused
        self::assertSame($purchase['id'], $this->purchase($body, $key)['id']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM purchase'));
        self::assertSame('idempotency_conflict', $this->purchase(['reference' => 'B24-OTHER'] + $body, $key, 409)['code']);
        self::assertSame($purchase, $this->getJson('/api/admin/purchases/'.$purchase['id']));
    }

    public function testMixedSuppliersAndLinesThatCantBeBoughtAreRefused(): void
    {
        $this->token = $this->loginAdmin();
        $confirmed = $this->placeOrder([$this->offered('T-P-R-B24', 30000) => 1, $this->offered('T-P-R-INN', 30000, 'bikeinn') => 1, $this->snapshotOnly('T-P-R-NONE') => 1]);
        $requested = $this->placeOrder([$this->offered('T-P-R-REQ', 30000) => 1]);
        $this->act($confirmed, 'confirm', self::AGREED);
        $order = $this->order($confirmed);
        [$b24, $inn, $none] = [$this->line($order, 'T-P-R-B24')['id'], $this->line($order, 'T-P-R-INN')['id'], $this->line($order, 'T-P-R-NONE')['id']];
        $body = static fn (array $itemIds, array $extra = []): array => $extra + [
            'supplier' => 'bike24', 'reference' => 'R-1', 'currency' => 'CZK',
            'lines' => array_map(static fn (string $id): array => ['itemId' => $id, 'unitPriceMinor' => 20000], $itemIds),
        ];

        $error = $this->purchase($body([$b24, $inn]), status: 422);
        self::assertSame(['mixed_suppliers', $inn], [$error['code'], $error['itemId']]);
        $error = $this->purchase($body([$b24, $this->order($requested)['items'][0]['id']]), status: 409);
        self::assertSame('line_not_purchasable', $error['code']);
        self::assertSame('invalid_field', $this->purchase($body([$b24, '01890000-0000-7000-8000-000000000000']), status: 422)['code']);
        self::assertSame('lines[1].itemId', $this->purchase($body([$b24, $b24]), status: 422)['violations'][0]['field']);
        self::assertSame('fxRateCzk', $this->purchase($body([$b24], ['currency' => 'EUR']), status: 422)['violations'][0]['field']);
        self::assertSame('fxRateCzk', $this->purchase($body([$b24], ['fxRateCzk' => 25_000_000]), status: 422)['violations'][0]['field']);
        self::assertSame('invalid_idempotency_key', $this->purchase($body([$b24]), '', 422)['code']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM purchase'));
        self::assertSame(['to_order', 'to_order', 'to_order'], array_column($this->order($confirmed)['items'], 'procurementStatus'), 'nothing was written');

        // a line without a source goes with any supplier; a line already ordered can't be bought again
        $purchase = $this->purchase($body([$b24, $none]));
        self::assertCount(2, $purchase['lines']);
        self::assertSame('line_not_purchasable', $this->purchase($body([$b24], ['reference' => 'R-2']), status: 409)['code']);
    }

    public function testReceivingMarksWhatIsStillOrderedAndLineActionsKeepWorking(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->offered('T-P-IN-1', 30000) => 1, $this->offered('T-P-IN-2', 30000) => 1, $this->offered('T-P-IN-3', 30000) => 1, $this->offered('T-P-IN-4', 30000) => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $order = $this->order($id);
        $purchase = $this->purchase(['supplier' => 'bike24', 'reference' => 'B24-IN', 'currency' => 'CZK', 'lines' => array_map(
            static fn (array $item): array => ['itemId' => $item['id'], 'unitPriceMinor' => 20000], $order['items'],
        )]);
        // one arrives early, one fails, one is cancelled while on its way
        $this->act($id, 'items/'.$this->line($order, 'T-P-IN-1')['id'].'/received');
        $this->act($id, 'items/'.$this->line($order, 'T-P-IN-2')['id'].'/failed', ['reason' => 'Supplier cancelled it']);
        $this->act($id, 'items/'.$this->line($order, 'T-P-IN-3')['id'].'/cancel', ['reason' => 'Customer dropped it']);

        $received = $this->sendJson('POST', '/api/admin/purchases/'.$purchase['id'].'/receive', [], $this->token);
        self::assertResponseIsSuccessful();

        self::assertSame(['received', []], [$received['status'], $received['actions']]);
        self::assertNotNull($received['receivedAt']);
        $order = $this->order($id);
        self::assertSame(['received', 'failed', 'received', 'received'], array_map(fn (string $sku): string => $this->line($order, $sku)['procurementStatus'], ['T-P-IN-1', 'T-P-IN-2', 'T-P-IN-3', 'T-P-IN-4']));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT stock FROM product_variant WHERE sku = 'T-P-IN-3'"), 'the cancelled line is own stock now');
        $events = array_values(array_filter($order['events'], static fn (array $e): bool => $e['type'] === 'item_received' && ($e['data']['purchaseId'] ?? null) === $purchase['id']));
        self::assertSame(['T-P-IN-3', 'T-P-IN-4'], array_column(array_column($events, 'data'), 'sku'));

        self::assertSame('action_not_allowed', $this->sendJson('POST', '/api/admin/purchases/'.$purchase['id'].'/receive', [], $this->token)['code']);
        self::assertResponseStatusCodeSame(409);
        $this->sendJson('POST', '/api/admin/purchases/'.$purchase['id'].'/cancel', ['reason' => 'Too late'], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$purchase['id']], array_column($this->getJson('/api/admin/purchases', ['status' => 'received'])['items'], 'id'));
        self::assertSame(0, $this->getJson('/api/admin/purchases', ['status' => 'ordered'])['total']);
    }

    public function testCancellingPutsTheLinesBackToBuyWithoutAnActualCost(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->offered('T-P-X-1', 30000) => 1, $this->offered('T-P-X-2', 30000) => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $items = array_column($this->order($id)['items'], 'id');
        $purchase = $this->purchase(['supplier' => 'bike24', 'reference' => 'B24-X', 'currency' => 'CZK', 'inboundShippingMinor' => 900,
            'lines' => array_map(static fn (string $item): array => ['itemId' => $item, 'unitPriceMinor' => 21000], $items)]);

        $cancelled = $this->sendJson('POST', '/api/admin/purchases/'.$purchase['id'].'/cancel', ['reason' => 'Seller never confirmed'], $this->token);
        self::assertResponseIsSuccessful();

        self::assertSame(['cancelled', 'Seller never confirmed', []], [$cancelled['status'], $cancelled['cancelReason'], $cancelled['actions']]);
        $order = $this->order($id);
        foreach ($order['items'] as $item) {
            self::assertSame(['to_order', null, null, null], [$item['procurementStatus'], $item['supplierReference'], $item['actualUnitCostCzkMinor'], $item['purchase']]);
        }
        $event = end($order['events']);
        self::assertSame(['purchase_cancelled', 'Seller never confirmed', $purchase['id']], [$event['type'], $event['data']['reason'], $event['data']['purchaseId']]);
        self::assertEqualsCanonicalizing($items, $event['data']['itemIds']);
        self::assertSame(2, $this->getJson('/api/admin/to-purchase')['lineCount']);

        // bought again elsewhere: the new purchase gives the cost
        $again = $this->purchase(['supplier' => 'bike24', 'reference' => 'B24-Y', 'currency' => 'CZK', 'lines' => [['itemId' => $items[0], 'unitPriceMinor' => 19000]]]);
        self::assertSame([19000, 'B24-Y'], [$this->order($id)['items'][0]['actualUnitCostCzkMinor'], $this->order($id)['items'][0]['purchase']['reference']]);

        // once something of it arrived, a purchase can't be cancelled
        $this->act($id, 'items/'.$items[0].'/received');
        $error = $this->sendJson('POST', '/api/admin/purchases/'.$again['id'].'/cancel', ['reason' => 'x'], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['purchase_partly_received', $items[0]], [$error['code'], $error['itemId']]);
    }

    public function testExpectedResultUsesSnapshotsUntilActualCostsArrive(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->offered('T-P-E-1', 60000, offerPrice: 20000) => 1, $this->offered('T-P-E-2', 40000, offerPrice: 25000) => 1]);
        $this->act($id, 'confirm', self::AGREED);

        $economics = $this->order($id)['economics'];
        self::assertSame([100000, 45000, 55000, 12222, false], [$economics['goodsRevenueMinor'], $economics['costMinor'], $economics['expectedResultMinor'], $economics['marginBp'], $economics['costComplete']]);

        $this->purchase(['supplier' => 'bike24', 'reference' => 'B24-E', 'currency' => 'CZK', 'inboundShippingMinor' => 1000,
            'lines' => [['itemId' => $this->line($this->order($id), 'T-P-E-1')['id'], 'unitPriceMinor' => 22000]]]);
        $economics = $this->order($id)['economics'];
        self::assertSame([48000, 52000, false], [$economics['costMinor'], $economics['expectedResultMinor'], $economics['costComplete']]);
    }

    /** The portfolio demo data prices lines from the seeded demo supplier; its purchases say so (P04). */
    public function testADemoOfferLineBuysUnderTheDemoSupplier(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->offered('T-P-DEMO-1', 30000, 'demo') => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $order = $this->order($id);

        $purchase = $this->purchase(['supplier' => 'demo', 'reference' => 'DEMO-P04', 'currency' => 'CZK', 'lines' => [
            ['itemId' => $order['items'][0]['id'], 'unitPriceMinor' => 20000],
        ]]);

        self::assertSame(['demo', 'ordered', 'DEMO-P04'], [$purchase['supplier'], $purchase['status'], $purchase['reference']]);
        self::assertSame('ordered', $this->line($this->order($id), 'T-P-DEMO-1')['procurementStatus']);
    }

    public function testUnknownPurchasesAreNotFound(): void
    {
        $this->token = $this->loginAdmin();
        foreach (['GET' => '', 'POST' => '/receive'] as $method => $suffix) {
            $this->sendJson($method, '/api/admin/purchases/01890000-0000-7000-8000-000000000000'.$suffix, [], $this->token);
            self::assertResponseStatusCodeSame(404, $method);
        }
        $this->sendJson('POST', '/api/admin/purchases/01890000-0000-7000-8000-000000000000/cancel', ['reason' => 'x'], $this->token);
        self::assertResponseStatusCodeSame(404);
        $this->getJson('/api/admin/purchases/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('POST', '/api/admin/purchases', ['supplier' => 'bike24', 'reference' => 'X', 'currency' => 'CZK', 'lines' => [['itemId' => '01890000-0000-7000-8000-000000000000', 'unitPriceMinor' => 1]]], 'not-the-token', ['HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame(403);
    }

    private function purchase(array $body, ?string $key = null, int $status = 201): array
    {
        $result = $this->sendJson('POST', '/api/admin/purchases', $body, $this->token, ['HTTP_IDEMPOTENCY_KEY' => $key ?? bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame($status, json_encode($result));

        return $result;
    }

    /** A variant with only a product-level snapshot: check_needed, no offer on the line. */
    private function snapshotOnly(string $sku): string
    {
        $b = $this->builder();
        $product = $b->product(strtolower($sku), $b->category(strtolower($sku).'-cat'));
        $variant = $b->variant($product, $sku, priceCzk: 30000);
        $b->pricedOffer($product, null, 20000);

        return $variant->getId()->toRfc4122();
    }
}
