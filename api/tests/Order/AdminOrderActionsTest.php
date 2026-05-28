<?php

declare(strict_types=1);

namespace App\Tests\Order;

use App\Entity\CustomerAccount;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/** Taking a real order from request to paid handover, and every exception on the way, through the admin API. */
final class AdminOrderActionsTest extends ApiTestCase
{
    private const AGREED = ['customerAgreedVia' => ['channel' => 'whatsapp', 'note' => 'Customer said yes']];
    private const WINDOW = ['from' => '2026-10-01T17:00:00+02:00', 'to' => '2026-10-01T19:00:00+02:00'];

    private string $token = '';

    public function testRequestToPaidHandoverCompletesAndEarnsPointsOnce(): void
    {
        $held = $this->held('T-A-HELD', 30000, 2);
        $offered = $this->offered('T-A-OFFER', 40000);
        $this->asCustomer('google-flow');
        $receipt = $this->checkout([$held => 1, $offered => 1], fulfilment: 'split');
        self::assertResponseStatusCodeSame(201);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);

        $order = $this->getJson('/api/admin/orders/'.$id);
        self::assertSame(['confirm', 'cancel', 'record_payment'], $order['actions']);
        self::assertSame([0, 0, 70000], [$order['shipments'][0]['fee']['amount'], $order['shipments'][1]['fee']['amount'], $order['total']['amount']]);
        [$stockShipment, $supplierShipment] = $order['shipments'];
        self::assertSame([true, false], [$stockShipment['ready'], $supplierShipment['ready']]);

        $order = $this->act($id, 'confirm', self::AGREED);
        self::assertSame('confirmed', $order['status']);
        $this->act($id, 'confirm', self::AGREED, 409);

        $line = $this->line($order, 'T-A-OFFER');
        self::assertSame(['change_terms', 'mark_ordered', 'mark_failed', 'cancel'], $line['actions']);
        $order = $this->act($id, 'items/'.$line['id'].'/ordered', ['supplierReference' => 'B24-1001']);
        self::assertSame(['ordered', 'B24-1001'], [$this->line($order, 'T-A-OFFER')['procurementStatus'], $this->line($order, 'T-A-OFFER')['supplierReference']]);

        $this->act($id, 'shipments/'.$stockShipment['id'].'/schedule', self::WINDOW);
        $order = $this->act($id, 'shipments/'.$stockShipment['id'].'/hand-over');
        self::assertSame(['handed_over', 'confirmed'], [$order['shipments'][0]['status'], $order['status']]);
        $order = $this->pay($id, 30000, shipmentId: $stockShipment['id'])['order'];
        self::assertSame(['partially_paid', 40000], [$order['paymentStatus'], $order['amountDue']['amount']]);

        $this->act($id, 'items/'.$line['id'].'/received');
        $order = $this->act($id, 'shipments/'.$supplierShipment['id'].'/schedule', self::WINDOW);
        self::assertTrue($order['shipments'][1]['ready']);
        $order = $this->act($id, 'shipments/'.$supplierShipment['id'].'/hand-over');
        self::assertSame('confirmed', $order['status'], 'not paid in full yet');

        $order = $this->pay($id, 40000)['order'];
        self::assertSame(['completed', 'paid', ['record_refund']], [$order['status'], $order['paymentStatus'], $order['actions']]);
        self::assertSame([[7, 'earn']], array_map(static fn (array $row): array => [(int) $row['points'], $row['reason']], $this->db()->fetchAllAssociative('SELECT points, reason FROM loyalty_entry WHERE shop_order_id = :id', ['id' => $id])));
        $this->pay($id, 100, status: 409);

        self::assertSame([
            ['placed', 'customer'], ['confirmed', 'test-admin@nodra.test'], ['item_ordered', 'test-admin@nodra.test'],
            ['shipment_scheduled', 'test-admin@nodra.test'], ['shipment_handed_over', 'test-admin@nodra.test'], ['payment_recorded', 'test-admin@nodra.test'],
            ['item_received', 'test-admin@nodra.test'], ['shipment_scheduled', 'test-admin@nodra.test'], ['shipment_handed_over', 'test-admin@nodra.test'],
            ['payment_recorded', 'test-admin@nodra.test'], ['completed', 'system'],
        ], array_map(static fn (array $e): array => [$e['type'], $e['actor']], $order['events']));
        // jsonb keeps its own key order
        self::assertEquals(['goodsMinor' => 70000, 'points' => 7, 'refundedBeforeMinor' => 0], $order['events'][10]['data']);

        $me = $this->getJson('/api/account/me');
        self::assertSame(7, $me['points']);
    }

    public function testActionsOutOfTurnAreRefused(): void
    {
        $offered = $this->offered('T-A-TURN', 40000);
        $receipt = $this->checkout([$offered => 1]);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $order = $this->getJson('/api/admin/orders/'.$id);
        $line = $order['items'][0]['id'];
        $shipment = $order['shipments'][0]['id'];

        // nothing is bought, scheduled or taken back before the customer agreed
        foreach ([['items/'.$line.'/ordered', ['supplierReference' => 'X']], ['items/'.$line.'/received', []], ['items/'.$line.'/return', ['reason' => 'x']],
            ['items/'.$line.'/replacement', ['variantId' => $offered] + self::AGREED], ['shipments/'.$shipment.'/schedule', self::WINDOW],
            ['shipments/'.$shipment.'/hand-over', []], ['shipments/'.$shipment.'/refused', ['reason' => 'x']]] as [$path, $payload]) {
            $error = $this->act($id, $path, $payload, 409);
            self::assertSame('action_not_allowed', $error['code'], $path);
        }
        $this->act($id, 'confirm', self::AGREED);
        $error = $this->act($id, 'shipments/'.$shipment.'/hand-over', [], 409);
        self::assertSame('hand_over', $error['action']);
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        // the goods aren't here yet
        $this->act($id, 'shipments/'.$shipment.'/hand-over', [], 409);
        $this->act($id, 'shipments/'.$shipment.'/refused', ['reason' => 'x'], 409);
        $this->act($id, 'shipments/'.$shipment.'/schedule', ['from' => '2026-10-01T19:00:00+02:00', 'to' => '2026-10-01T17:00:00+02:00'], 422);

        $this->act($id, 'items/01890000-0000-7000-8000-000000000000/received', [], 404);
        $this->act('01890000-0000-7000-8000-000000000000', 'confirm', self::AGREED, 404);
        $events = array_column($this->getJson('/api/admin/orders/'.$id)['events'], 'type');
        self::assertSame(['placed', 'confirmed', 'shipment_scheduled'], $events, 'refused actions leave no trace');
    }

    public function testChangedTermsReopenTheOrderAndNeverRaiseTheFee(): void
    {
        $receipt = $this->checkout([$this->offered('T-A-TERMS', 60000) => 1]);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $this->act($id, 'confirm', self::AGREED);
        $line = $this->getJson('/api/admin/orders/'.$id)['items'][0]['id'];

        $order = $this->act($id, 'items/'.$line.'/terms', ['unitPriceMinor' => 30000, 'leadTimeMinDays' => 5, 'leadTimeMaxDays' => 9]);

        self::assertSame(['requested', 30000, 0, 30000], [$order['status'], $order['subtotal']['amount'], $order['shipping']['amount'], $order['total']['amount']]);
        self::assertSame([5, 9], [$order['shipments'][0]['leadTimeMinDays'], $order['shipments'][0]['leadTimeMaxDays']]);
        $event = end($order['events']);
        self::assertSame(['terms_changed', true, 60000, 30000], [$event['type'], $event['data']['reopened'], $event['data']['before']['unitPriceMinor'], $event['data']['after']['unitPriceMinor']]);
        $receipt = $this->getJson('/api/orders/'.$receipt['reference'], ['token' => $receipt['lookupToken']]);
        self::assertSame(['requested', 0, 5, 9], [$receipt['status'], $receipt['shipping']['amount'], $receipt['items'][0]['leadTimeMinDays'], $receipt['items'][0]['leadTimeMaxDays']]);

        self::assertSame('leadTimeMaxDays', $this->act($id, 'items/'.$line.'/terms', ['leadTimeMinDays' => 3], 422)['violations'][0]['field']);
        $this->act($id, 'items/'.$line.'/terms', [], 422);
        self::assertSame('confirmed', $this->act($id, 'confirm', self::AGREED)['status']);
    }

    public function testFailedLineIsReplacedOnlyWithTheCustomersAgreement(): void
    {
        $failing = $this->offered('T-A-FAIL', 40000);
        $other = $this->offered('T-A-OTHER', 20000);
        $replacement = $this->held('T-A-REPL', 45000, 1);
        $receipt = $this->checkout([$failing => 1, $other => 1]);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $failed = $this->line($this->getJson('/api/admin/orders/'.$id), 'T-A-FAIL');

        $order = $this->act($id, 'items/'.$failed['id'].'/failed', ['reason' => 'Supplier sold out']);
        self::assertNotContains('confirm', $order['actions']);
        $this->act($id, 'confirm', self::AGREED, 409);
        self::assertSame('customerAgreedVia', $this->act($id, 'items/'.$failed['id'].'/replacement', ['variantId' => $replacement], 422)['violations'][0]['field']);

        $order = $this->act($id, 'items/'.$failed['id'].'/replacement', ['variantId' => $replacement] + self::AGREED);

        $new = $this->line($order, 'T-A-REPL');
        self::assertSame(['cancelled', 'active', 'from_stock', $failed['id'], $failed['shipmentId'], 45000], [
            $this->line($order, 'T-A-FAIL')['state'], $new['state'], $new['procurementStatus'], $new['replacesItemId'], $new['shipmentId'], $new['unitPrice'],
        ]);
        self::assertSame(65000, $order['subtotal']['amount']);
        self::assertSame(0, $this->stock('T-A-REPL'));
        $event = end($order['events']);
        self::assertSame('replacement_added', $event['type']);
        self::assertEquals(self::AGREED['customerAgreedVia'], $event['data']['customerAgreedVia']);
        self::assertSame('confirmed', $this->act($id, 'confirm', self::AGREED)['status']);
    }

    public function testCancellingGivesReservedAndReceivedGoodsBackToOwnStock(): void
    {
        $held = $this->held('T-A-C-HELD', 30000, 2);
        $received = $this->offered('T-A-C-RECV', 20000);
        $ordered = $this->offered('T-A-C-ORD', 20000);
        $receipt = $this->checkout([$held => 1, $received => 1, $ordered => 1]);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $order = $this->act($id, 'confirm', self::AGREED);
        $this->act($id, 'items/'.$this->line($order, 'T-A-C-RECV')['id'].'/ordered', ['supplierReference' => 'R-1']);
        $this->act($id, 'items/'.$this->line($order, 'T-A-C-RECV')['id'].'/received');
        $this->act($id, 'items/'.$this->line($order, 'T-A-C-ORD')['id'].'/ordered', ['supplierReference' => 'R-2']);
        $this->pay($id, 10000);
        self::assertSame(1, $this->stock('T-A-C-HELD'));

        $order = $this->act($id, 'cancel', ['reason' => 'Customer changed their mind']);

        self::assertSame(['cancelled', ['cancelled'], 0, 'paid', 10000], [$order['status'], array_values(array_unique(array_column($order['items'], 'state'))), $order['total']['amount'], $order['paymentStatus'], $order['refundDue']['amount']]);
        self::assertSame(['cancelled'], array_values(array_unique(array_column($order['shipments'], 'status'))));
        self::assertSame(['record_refund'], $order['actions']);
        self::assertSame([2, 1, 0], [$this->stock('T-A-C-HELD'), $this->stock('T-A-C-RECV'), $this->stock('T-A-C-ORD')]);

        // the ordered goods arrive after all and become own stock
        $late = $this->line($order, 'T-A-C-ORD');
        self::assertSame(['mark_received'], $late['actions']);
        $this->act($id, 'items/'.$late['id'].'/received');
        self::assertSame(1, $this->stock('T-A-C-ORD'));
        self::assertSame([
            ['T-A-C-HELD', -1], ['T-A-C-HELD', 1], ['T-A-C-RECV', 1], ['T-A-C-ORD', 1],
        ], array_map(static fn (array $r): array => [$r['sku'], (int) $r['delta']], $this->db()->fetchAllAssociative(
            'SELECT v.sku, m.delta FROM stock_movement m JOIN product_variant v ON v.id = m.variant_id WHERE m.order_id = :id ORDER BY m.id', ['id' => $id],
        )));

        $order = $this->pay($id, 10000, 'refund', method: 'bank_transfer')['order'];
        self::assertSame(['refunded', 0, []], [$order['paymentStatus'], $order['refundDue']['amount'], $order['actions']]);
    }

    public function testCancelledLineDropsItsEmptyShipmentButNoFeeGoesUp(): void
    {
        $held = $this->held('T-A-F-HELD', 20000, 1);
        $offered = $this->offered('T-A-F-OFFER', 20000);
        $receipt = $this->checkout([$held => 1, $offered => 1], fulfilment: 'split');
        self::assertSame(29800, $receipt['shipping']['amount']);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $order = $this->getJson('/api/admin/orders/'.$id);

        $order = $this->act($id, 'items/'.$this->line($order, 'T-A-F-OFFER')['id'].'/cancel', ['reason' => 'Out of stock everywhere']);

        self::assertSame([20000, 14900, 34900], [$order['subtotal']['amount'], $order['shipping']['amount'], $order['total']['amount']]);
        self::assertSame(['planned', 'cancelled'], array_column($order['shipments'], 'status'));
        self::assertSame(['item_cancelled', 'shipment_cancelled'], array_slice(array_column($order['events'], 'type'), -2));

        $order = $this->act($id, 'items/'.$this->line($order, 'T-A-F-HELD')['id'].'/cancel', ['reason' => 'Nothing left to deliver']);
        self::assertSame(['cancelled', 0], [$order['status'], $order['total']['amount']]);
        self::assertSame(1, $this->stock('T-A-F-HELD'));
    }

    public function testFreeDeliveryStaysFreeWhenTheGoodsDropBelow500(): void
    {
        $a = $this->offered('T-A-500-A', 30000);
        $b = $this->offered('T-A-500-B', 30000);
        $receipt = $this->checkout([$a => 1, $b => 1]);
        self::assertSame(0, $receipt['shipping']['amount']);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);

        $order = $this->act($id, 'items/'.$this->line($this->getJson('/api/admin/orders/'.$id), 'T-A-500-B')['id'].'/cancel', ['reason' => 'x']);

        self::assertSame([30000, 0], [$order['subtotal']['amount'], $order['shipping']['amount']]);
    }

    public function testRefusedGoodsGoToOwnStockAndReplanningTakesThemBack(): void
    {
        $receipt = $this->checkout([$this->held('T-A-REF', 60000, 1) => 1]);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $this->act($id, 'confirm', self::AGREED);
        $shipment = $this->getJson('/api/admin/orders/'.$id)['shipments'][0]['id'];
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        self::assertSame(0, $this->stock('T-A-REF'));

        $order = $this->act($id, 'shipments/'.$shipment.'/refused', ['reason' => 'Not at home, did not want it']);
        self::assertSame(['refused', false, ['schedule']], [$order['shipments'][0]['status'], $order['shipments'][0]['ready'], $order['shipments'][0]['actions']]);
        self::assertSame(1, $this->stock('T-A-REF'));

        $order = $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        self::assertSame(['scheduled', 'from_stock', 0], [$order['shipments'][0]['status'], $order['items'][0]['procurementStatus'], $this->stock('T-A-REF')]);

        $this->act($id, 'shipments/'.$shipment.'/refused', ['reason' => 'Refused again']);
        // meanwhile the piece was sold over the counter
        $this->db()->executeStatement("UPDATE product_variant SET stock = 0 WHERE sku = 'T-A-REF'");
        self::assertSame('not_enough_stock', $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW, 409)['code']);
        $this->act($id, 'cancel', ['reason' => 'Refused twice']);
        self::assertSame(0, $this->stock('T-A-REF'), 'refused goods were already own stock');
    }

    public function testReturnAndRefundTakeBackPointsButNeverMoreThanEarned(): void
    {
        $kept = $this->held('T-A-KEPT', 60000, 1);
        $back = $this->held('T-A-BACK', 40500, 1);
        $this->asCustomer('google-return');
        $receipt = $this->checkout([$kept => 1, $back => 1]);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $this->act($id, 'confirm', self::AGREED);
        $shipment = $this->getJson('/api/admin/orders/'.$id)['shipments'][0]['id'];
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        $this->act($id, 'shipments/'.$shipment.'/hand-over');
        $order = $this->pay($id, 100500)['order'];
        self::assertSame(['completed', 10], [$order['status'], $this->points()]);

        $order = $this->act($id, 'items/'.$this->line($order, 'T-A-BACK')['id'].'/return', ['reason' => 'Withdrawal within 14 days']);
        self::assertSame(['completed', 'returned', 60000, 40500, 1], [$order['status'], $this->line($order, 'T-A-BACK')['state'], $order['total']['amount'], $order['refundDue']['amount'], $this->stock('T-A-BACK')]);

        $order = $this->pay($id, 40500, 'refund', method: 'bank_transfer')['order'];
        self::assertSame(['partially_refunded', 6], [$order['paymentStatus'], $this->points()]);
        self::assertSame(['refund_recorded', 'loyalty_adjusted'], array_slice(array_column($order['events'], 'type'), -2));

        // a goodwill refund of everything else takes the rest, not more
        $order = $this->pay($id, 60000, 'refund', method: 'cash')['order'];
        self::assertSame(['refunded', 0], [$order['paymentStatus'], $this->points()]);
        self::assertSame([[10, 'earn'], [-4, 'refund'], [-6, 'refund']], array_map(static fn (array $r): array => [(int) $r['points'], $r['reason']], $this->db()->fetchAllAssociative('SELECT points, reason FROM loyalty_entry WHERE shop_order_id = :id ORDER BY id', ['id' => $id])));
        $this->pay($id, 100, 'refund', status: 409);
    }

    public function testListFiltersByOrderAndPaymentStatus(): void
    {
        $first = $this->checkout([$this->held('T-A-LIST-1', 60000, 1) => 1]);
        $second = $this->checkout([$this->offered('T-A-LIST-2', 60000) => 1]);
        $this->token = $this->loginAdmin();
        $id = $this->orderId($first['reference']);
        $this->act($id, 'confirm', self::AGREED);
        $this->pay($id, 1000);

        $confirmed = $this->getJson('/api/admin/orders', ['status' => 'confirmed']);
        self::assertSame([[$first['reference'], 'confirmed', 'partially_paid']], array_map(static fn (array $o): array => [$o['reference'], $o['status'], $o['paymentStatus']], $confirmed['items']));
        self::assertSame([$second['reference']], array_column($this->getJson('/api/admin/orders', ['paymentStatus' => 'unpaid'])['items'], 'reference'));
        self::assertSame(0, $this->getJson('/api/admin/orders', ['status' => 'requested', 'paymentStatus' => 'partially_paid'])['total']);
        self::assertSame('+420777123456', $confirmed['items'][0]['phone']);
    }

    /** POST an admin action and check the status; returns the order, or the problem. */
    private function act(string $id, string $action, array $payload = [], int $status = 200): array
    {
        $result = $this->sendJson('POST', '/api/admin/orders/'.$id.'/'.$action, $payload, $this->token);
        self::assertResponseStatusCodeSame($status, $action.': '.json_encode($result));

        return $result;
    }

    private function pay(string $id, int $amount, string $kind = 'payment', string $method = 'cash', ?string $shipmentId = null, int $status = 201): array
    {
        $result = $this->sendJson('POST', '/api/admin/orders/'.$id.'/payments', ['kind' => $kind, 'method' => $method, 'amountMinor' => $amount, 'shipmentId' => $shipmentId], $this->token, ['HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame($status, json_encode($result));

        return $result;
    }

    private function line(array $order, string $sku): array
    {
        foreach ($order['items'] as $item) {
            if ($item['sku'] === $sku) {
                return $item;
            }
        }
        self::fail('No line '.$sku);
    }

    private function asCustomer(string $sub): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $account = new CustomerAccount($sub, 'rider@example.test', 'Rider');
        $em->persist($account);
        $em->flush();
        $this->signInCustomer($account);
    }

    private function points(): int
    {
        return (int) $this->db()->fetchOne("SELECT COALESCE(SUM(e.points), 0) FROM loyalty_entry e JOIN customer_account a ON a.id = e.account_id WHERE a.email = 'rider@example.test'");
    }

    private function stock(string $sku): int
    {
        return (int) $this->db()->fetchOne('SELECT stock FROM product_variant WHERE sku = :sku', ['sku' => $sku]);
    }

    private function offered(string $sku, int $priceCzk): string
    {
        $product = $this->builder()->product(strtolower($sku), $this->builder()->category(strtolower($sku).'-cat'));
        $variant = $this->builder()->variant($product, $sku, priceCzk: $priceCzk);
        $this->builder()->pricedOffer($product, $variant, 20000);

        return $variant->getId()->toRfc4122();
    }

    private function held(string $sku, int $priceCzk, int $stock): string
    {
        $product = $this->builder()->product(strtolower($sku), $this->builder()->category(strtolower($sku).'-cat'));

        return $this->builder()->variant($product, $sku, stock: $stock, priceCzk: $priceCzk)->getId()->toRfc4122();
    }
}
