<?php

declare(strict_types=1);

namespace App\Tests\Order;

use App\Tests\Support\AdminOrderSteps;
use App\Tests\Support\ApiTestCase;

/** Corrections through the admin API: each needs a reason, writes the journal and is refused out of turn. */
final class OrderCorrectionsTest extends ApiTestCase
{
    use AdminOrderSteps;

    public function testMovingToANewPartIsFreeAndNeverRaisesAFee(): void
    {
        $this->token = $this->loginAdmin();
        // 400 Kč of goods: one Prague delivery at 149 Kč
        $id = $this->placeOrder([$this->held('T-M-A', 20000) => 1, $this->held('T-M-B', 20000) => 1]);
        $order = $this->order($id);
        self::assertSame([14900, 54900], [$order['shipping']['amount'], $order['total']['amount']]);
        self::assertSame(['move'], $this->line($order, 'T-M-B')['corrections']);

        $order = $this->act($id, 'items/'.$this->line($order, 'T-M-B')['id'].'/move', ['shipmentId' => null, 'reason' => 'B is ready earlier']);

        self::assertSame([[1, 'planned', 14900], [2, 'planned', 0]], array_map(static fn (array $s): array => [$s['number'], $s['status'], $s['fee']['amount']], $order['shipments']));
        self::assertSame($order['shipments'][1]['id'], $this->line($order, 'T-M-B')['shipmentId']);
        self::assertSame([14900, 54900, 'prague_personal'], [$order['shipping']['amount'], $order['total']['amount'], $order['shipments'][1]['method']]);
        $event = end($order['events']);
        self::assertSame(['item_moved', 'test-admin@nodra.test', 'B is ready earlier', true, 1, 2], [$event['type'], $event['actor'], $event['data']['reason'], $event['data']['newPart'], $event['data']['fromNumber'], $event['data']['toNumber']]);

        // the last line leaves part 1: it's cancelled with its fee, the total only goes down
        $order = $this->act($id, 'items/'.$this->line($order, 'T-M-A')['id'].'/move', ['shipmentId' => $order['shipments'][1]['id'], 'reason' => 'All in one go after all']);
        self::assertSame([['cancelled', 14900], ['planned', 0]], array_map(static fn (array $s): array => [$s['status'], $s['fee']['amount']], $order['shipments']));
        self::assertSame([0, 40000], [$order['shipping']['amount'], $order['total']['amount']]);
        self::assertSame(['item_moved', 'shipment_cancelled'], array_slice($this->eventTypes($order), -2));
    }

    public function testMovingIsRefusedOutOfOrIntoAClosedShipment(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->held('T-M-C', 30000) => 1, $this->held('T-M-D', 30000) => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $order = $this->act($id, 'items/'.$this->line($this->order($id), 'T-M-D')['id'].'/move', ['reason' => 'Two trips']);
        [$first, $second] = array_column($order['shipments'], 'id');
        $this->act($id, 'shipments/'.$first.'/schedule', self::WINDOW);
        $this->act($id, 'shipments/'.$first.'/hand-over');
        $order = $this->order($id);

        self::assertSame([], $this->line($order, 'T-M-C')['corrections'], 'handed over');
        self::assertSame('move', $this->act($id, 'items/'.$this->line($order, 'T-M-C')['id'].'/move', ['shipmentId' => $second, 'reason' => 'x'], 409)['action']);
        self::assertSame('shipment_closed', $this->act($id, 'items/'.$this->line($order, 'T-M-D')['id'].'/move', ['shipmentId' => $first, 'reason' => 'x'], 409)['code']);
        self::assertSame('shipmentId', $this->act($id, 'items/'.$this->line($order, 'T-M-D')['id'].'/move', ['shipmentId' => $second, 'reason' => 'x'], 422)['violations'][0]['field']);
        $this->act($id, 'items/'.$this->line($order, 'T-M-D')['id'].'/move', ['shipmentId' => '01890000-0000-7000-8000-000000000000', 'reason' => 'x'], 404);
        self::assertSame('reason', $this->act($id, 'items/'.$this->line($order, 'T-M-D')['id'].'/move', ['reason' => ' '], 422)['violations'][0]['field']);
        self::assertSame(1, count(array_filter($this->eventTypes($this->order($id)), static fn (string $t): bool => $t === 'item_moved')), 'refused moves leave no trace');
    }

    public function testRescheduleMovesAnAgreedWindowWithAReason(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->held('T-R-A', 60000) => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $shipment = $this->order($id)['shipments'][0];
        self::assertSame([], $shipment['corrections']);
        $later = ['from' => '2026-10-03T18:00:00+02:00', 'to' => '2026-10-03T20:00:00+02:00', 'reason' => 'Customer is away on Thursday'];
        self::assertSame('reschedule', $this->act($id, 'shipments/'.$shipment['id'].'/reschedule', $later, 409)['action'], 'nothing agreed yet');

        $this->act($id, 'shipments/'.$shipment['id'].'/schedule', self::WINDOW);
        self::assertSame(['reschedule'], $this->order($id)['shipments'][0]['corrections']);
        $order = $this->act($id, 'shipments/'.$shipment['id'].'/reschedule', $later);

        self::assertSame(['scheduled', '2026-10-03T16:00:00+00:00', '2026-10-03T18:00:00+00:00'], [$order['shipments'][0]['status'], $order['shipments'][0]['scheduledFrom'], $order['shipments'][0]['scheduledTo']]);
        $event = end($order['events']);
        self::assertSame(['shipment_rescheduled', 'Customer is away on Thursday', '2026-10-01T15:00:00+00:00', '2026-10-03T18:00:00+02:00'], [$event['type'], $event['data']['reason'], $event['data']['before']['from'], $event['data']['after']['from']]);
        $this->act($id, 'shipments/'.$shipment['id'].'/reschedule', ['from' => $later['to'], 'to' => $later['from'], 'reason' => 'x'], 422);
        $this->act($id, 'shipments/'.$shipment['id'].'/reschedule', ['from' => $later['from'], 'to' => $later['to']], 422);

        $this->act($id, 'shipments/'.$shipment['id'].'/hand-over');
        $this->act($id, 'shipments/'.$shipment['id'].'/reschedule', $later, 409);
    }

    public function testUndoReceivedUntilTheShipmentIsHandedOver(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->offered('T-U-A', 60000) => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $line = $this->order($id)['items'][0]['id'];
        $this->act($id, 'items/'.$line.'/ordered', ['supplierReference' => 'U-1']);
        self::assertSame(['move'], $this->order($id)['items'][0]['corrections']);
        $order = $this->act($id, 'items/'.$line.'/received');
        self::assertSame([['move', 'undo_received'], true], [$order['items'][0]['corrections'], $order['shipments'][0]['ready']]);

        $order = $this->act($id, 'items/'.$line.'/undo-received', ['reason' => 'Clicked the wrong line']);
        self::assertSame(['ordered', 'U-1', false], [$order['items'][0]['procurementStatus'], $order['items'][0]['supplierReference'], $order['shipments'][0]['ready']]);
        $event = end($order['events']);
        self::assertSame(['item_receipt_undone', 'Clicked the wrong line', 'test-admin@nodra.test'], [$event['type'], $event['data']['reason'], $event['actor']]);
        self::assertSame('undo_received', $this->act($id, 'items/'.$line.'/undo-received', ['reason' => 'Again'], 409)['action']);

        $this->act($id, 'items/'.$line.'/received');
        $shipment = $this->order($id)['shipments'][0]['id'];
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        $this->act($id, 'shipments/'.$shipment.'/hand-over');
        self::assertSame('action_not_allowed', $this->act($id, 'items/'.$line.'/undo-received', ['reason' => 'Too late'], 409)['code']);
        self::assertSame('received', $this->order($id)['items'][0]['procurementStatus']);
    }

    public function testVoidingAnEntryTwiceIsRefusedAndThePaymentStatusFollows(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->held('T-V-A', 60000) => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $payment = $this->pay($id, 20000)['payment'];
        self::assertSame([null, null, ['void']], [$payment['correctsId'], $payment['voidedById'], $payment['corrections']]);

        $order = $this->act($id, 'payments/'.$payment['id'].'/void', ['reason' => 'Typed 200 instead of 2000']);

        self::assertSame(['unpaid', 0, 60000], [$order['paymentStatus'], $order['paid']['amount'], $order['amountDue']['amount']]);
        [$voided, $correction] = $order['payments'];
        self::assertSame(['correction', 20000, 'cash', $payment['id'], 'Typed 200 instead of 2000', 'test-admin@nodra.test', []], [
            $correction['kind'], $correction['amount']['amount'], $correction['method'], $correction['correctsId'], $correction['note'], $correction['recordedBy'], $correction['corrections'],
        ]);
        self::assertSame([$correction['id'], []], [$voided['voidedById'], $voided['corrections']]);
        $event = end($order['events']);
        self::assertSame(['entry_voided', $payment['id'], 'payment', 20000, 'Typed 200 instead of 2000'], [$event['type'], $event['data']['voidedId'], $event['data']['kind'], $event['data']['amountMinor'], $event['data']['reason']]);

        self::assertSame(['action_not_allowed', 'void'], array_values(array_intersect_key($this->act($id, 'payments/'.$payment['id'].'/void', ['reason' => 'Again'], 409), array_flip(['code', 'action']))));
        $this->act($id, 'payments/'.$correction['id'].'/void', ['reason' => 'Undo the undo'], 409);
        $this->act($id, 'payments/01890000-0000-7000-8000-000000000000/void', ['reason' => 'x'], 404);
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = :id', ['id' => $id]));

        // the ledger is right again once the real amount is recorded
        self::assertSame('paid', $this->pay($id, 60000)['order']['paymentStatus']);
    }

    public function testAPaymentIsNotVoidedBelowNothingReceived(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->held('T-V-B', 60000) => 1]);
        $payment = $this->pay($id, 60000)['payment'];
        $refund = $this->pay($id, 50000, 'refund', 'bank_transfer')['payment'];

        $error = $this->act($id, 'payments/'.$payment['id'].'/void', ['reason' => 'x'], 422);
        self::assertSame(['void_exceeds_paid', 10000], [$error['code'], $error['paidMinor']]);

        $order = $this->act($id, 'payments/'.$refund['id'].'/void', ['reason' => 'The transfer bounced']);
        self::assertSame(['paid', 60000], [$order['paymentStatus'], $order['paid']['amount']]);
        $order = $this->act($id, 'payments/'.$payment['id'].'/void', ['reason' => 'Never paid']);
        self::assertSame(['unpaid', 0], [$order['paymentStatus'], $order['paid']['amount']]);
    }

    public function testVoidsOnACompletedOrderKeepThePointsInStepWithTheMoney(): void
    {
        $this->asCustomer('google-void');
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->held('T-V-KEPT', 60000) => 1, $this->held('T-V-BACK', 40500) => 1]);
        $this->act($id, 'confirm', self::AGREED);
        $shipment = $this->order($id)['shipments'][0]['id'];
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        $this->act($id, 'shipments/'.$shipment.'/hand-over');
        $payment = $this->pay($id, 100500)['payment'];
        self::assertSame(['completed', 10], [$this->order($id)['status'], $this->points()]);

        // a refund takes 4 points back; voiding the refund gives them back
        $refund = $this->pay($id, 40500, 'refund', 'bank_transfer')['payment'];
        self::assertSame(6, $this->points());
        $this->act($id, 'payments/'.$refund['id'].'/void', ['reason' => 'Refund was sent to the wrong order']);
        self::assertSame(10, $this->points());

        // the payment itself turns out wrong: the points go, the order is owed again but stays completed
        $order = $this->act($id, 'payments/'.$payment['id'].'/void', ['reason' => 'The cash was counterfeit']);
        self::assertSame(['completed', 'unpaid', 100500, ['unpaid'], ['record_payment']], [$order['status'], $order['paymentStatus'], $order['amountDue']['amount'], $order['queues'], $order['actions']]);
        self::assertSame(0, $this->points());

        $order = $this->pay($id, 100500, method: 'bank_transfer')['order'];
        self::assertSame(['completed', 'paid', [], ['record_refund']], [$order['status'], $order['paymentStatus'], $order['queues'], $order['actions']]);
        self::assertSame(10, $this->points());
        self::assertSame([[10, 'earn'], [-4, 'refund'], [4, 'correction'], [-10, 'correction'], [10, 'correction']], array_map(
            static fn (array $r): array => [(int) $r['points'], $r['reason']],
            $this->db()->fetchAllAssociative('SELECT points, reason FROM loyalty_entry WHERE shop_order_id = :id ORDER BY id', ['id' => $id]),
        ));
        self::assertSame([10, 'correction'], [$this->getJson('/api/account/me')['points'], $this->getJson('/api/account/me')['history'][0]['reason']]);
        self::assertSame(1, count(array_filter($this->eventTypes($order), static fn (string $t): bool => $t === 'completed')), 'completed once');
    }
}
