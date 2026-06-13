<?php

declare(strict_types=1);

namespace App\Tests\Order;

use App\Order\OrderQueues;
use App\Tests\Support\AdminOrderSteps;
use App\Tests\Support\ApiTestCase;

/**
 * The work queues through the API: counts, the queue= filter and the queues in the list and the order view must all
 * say the same, for an order on each side of each condition.
 */
final class QueuesApiTest extends ApiTestCase
{
    use AdminOrderSteps;

    public function testEveryQueueCountsFiltersAndShowsTheSameOrders(): void
    {
        $this->token = $this->loginAdmin();
        $empty = $this->getJson('/api/admin/queues');
        self::assertSame(array_fill_keys(OrderQueues::ALL, 0), $empty);

        $expected = [];
        // requested: to review, even when everything is on the shelf already
        $expected['review'] = [$this->placeOrder([$this->offered('T-QU-REVIEW', 30000) => 1]), ['review']];
        $expected['review, ready'] = [$this->placeOrder([$this->held('T-QU-REVIEW-HELD', 30000) => 1]), ['review']];
        // confirmed with a line to buy
        $toBuy = $this->placeOrder([$this->offered('T-QU-BUY', 30000) => 1]);
        $this->act($toBuy, 'confirm', self::AGREED);
        $expected['to purchase'] = [$toBuy, ['to_purchase']];
        // ordered: waiting, on its promised day and a day after it
        foreach (['on time' => 0, 'late' => 1] as $case => $daysLate) {
            $id = $this->placeOrder([$this->offered('T-QU-WAIT-'.$daysLate, 30000) => 1]);
            $this->act($id, 'confirm', self::AGREED);
            $line = $this->order($id)['items'][0];
            $this->act($id, 'items/'.$line['id'].'/ordered', ['supplierReference' => 'W-'.$daysLate]);
            $this->confirmedDaysAgo($id, $line['leadTimeMaxDays'] + $daysLate);
            $expected['waiting '.$case] = [$id, $daysLate === 1 ? ['waiting', 'delayed'] : ['waiting']];
        }
        // a failed line is a problem; its sibling is still to buy
        $problem = $this->placeOrder([$this->offered('T-QU-FAIL', 30000) => 1, $this->offered('T-QU-FAIL-2', 30000) => 1]);
        $this->act($problem, 'confirm', self::AGREED);
        $this->act($problem, 'items/'.$this->line($this->order($problem), 'T-QU-FAIL')['id'].'/failed', ['reason' => 'Sold out']);
        $expected['problem'] = [$problem, ['problem', 'to_purchase']];
        // own stock, confirmed: to schedule, then delivering, then handed over and not paid in full
        $shipments = [];
        foreach (['schedule', 'deliver', 'unpaid', 'paid'] as $step) {
            $id = $this->placeOrder([$this->held('T-QU-'.strtoupper($step), 60000) => 1]);
            $this->act($id, 'confirm', self::AGREED);
            $shipments[$step] = [$id, $this->order($id)['shipments'][0]['id']];
        }
        $expected['to schedule'] = [$shipments['schedule'][0], ['to_schedule']];
        [$id, $shipment] = $shipments['deliver'];
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        $expected['delivering'] = [$id, ['delivering']];
        [$id, $shipment] = $shipments['unpaid'];
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        $this->act($id, 'shipments/'.$shipment.'/hand-over');
        $this->pay($id, 20000);
        $expected['unpaid'] = [$id, ['unpaid']];
        [$id, $shipment] = $shipments['paid'];
        $this->act($id, 'shipments/'.$shipment.'/schedule', self::WINDOW);
        $this->act($id, 'shipments/'.$shipment.'/hand-over');
        $this->pay($id, 60000);
        $expected['completed'] = [$id, []];
        // cancelled after money came in: a refund is due, and nothing more once it's given back
        $refund = $this->placeOrder([$this->held('T-QU-REFUND', 60000) => 1]);
        $this->pay($refund, 10000);
        $this->act($refund, 'cancel', ['reason' => 'Changed mind']);
        $expected['refund'] = [$refund, ['refund']];
        $refunded = $this->placeOrder([$this->held('T-QU-REFUNDED', 60000) => 1]);
        $this->pay($refunded, 10000);
        $this->act($refunded, 'cancel', ['reason' => 'Changed mind']);
        $this->pay($refunded, 10000, 'refund');
        $expected['refunded'] = [$refunded, []];

        $counts = array_fill_keys(OrderQueues::ALL, 0);
        foreach ($expected as $case => [$id, $queues]) {
            self::assertSame($queues, $this->order($id)['queues'], 'order view: '.$case);
            foreach ($queues as $queue) {
                ++$counts[$queue];
            }
        }
        self::assertSame($counts, $this->getJson('/api/admin/queues'));
        self::assertSame($counts, $this->getJson('/api/admin/dashboard')['queues']);
        foreach (OrderQueues::ALL as $queue) {
            $page = $this->getJson('/api/admin/orders', ['queue' => $queue]);
            $inQueue = array_values(array_map(static fn (array $case): string => $case[0], array_filter($expected, static fn (array $case): bool => in_array($queue, $case[1], true))));
            self::assertEqualsCanonicalizing($inQueue, array_column($page['items'], 'id'), 'list: '.$queue);
            self::assertSame(count($inQueue), $page['total'], 'total: '.$queue);
        }
        $rows = array_column($this->getJson('/api/admin/orders')['items'], null, 'id');
        foreach ($expected as $case => [$id, $queues]) {
            self::assertSame([$queues, in_array('delayed', $queues, true)], [$rows[$id]['queues'], $rows[$id]['delayed']], 'row: '.$case);
        }
    }

    public function testPromisedDateAndDelayOnTheLine(): void
    {
        $this->token = $this->loginAdmin();
        $id = $this->placeOrder([$this->offered('T-QU-DATE', 30000, leadTimeMaxDays: 5) => 1, $this->offered('T-QU-NODATE', 30000, leadTimeMaxDays: null) => 1]);
        $order = $this->order($id);
        self::assertSame([null, null, false], [$order['confirmedAt'], $this->line($order, 'T-QU-DATE')['promisedDate'], $order['delayed']]);

        $this->act($id, 'confirm', self::AGREED);
        foreach (['T-QU-DATE', 'T-QU-NODATE'] as $sku) {
            $this->act($id, 'items/'.$this->line($this->order($id), $sku)['id'].'/ordered', ['supplierReference' => 'D-1']);
        }
        // 5 days from the supplier and a day of handling
        self::assertSame(6, $this->line($this->order($id), 'T-QU-DATE')['leadTimeMaxDays']);
        $confirmed = $this->confirmedDaysAgo($id, 7);

        $order = $this->order($id);
        $dated = $this->line($order, 'T-QU-DATE');
        self::assertSame([$confirmed->modify('+6 days')->format('Y-m-d'), true], [$dated['promisedDate'], $dated['delayed']]);
        self::assertSame([null, false], [$this->line($order, 'T-QU-NODATE')['promisedDate'], $this->line($order, 'T-QU-NODATE')['delayed']], 'no lead time, never late');
        self::assertTrue($order['delayed']);
        self::assertSame(1, $this->getJson('/api/admin/queues')['delayed']);

        // the goods arrive: nothing is late any more
        $this->act($id, 'items/'.$dated['id'].'/received');
        self::assertSame([false, 0], [$this->order($id)['delayed'], $this->getJson('/api/admin/queues')['delayed']]);
    }

    public function testSearchByReferenceNameEmailPhoneAndSupplierReference(): void
    {
        $this->token = $this->loginAdmin();
        $jana = $this->placeOrder([$this->offered('T-QS-JANA', 30000) => 1], customer: ['name' => 'Jana Nováková', 'email' => 'jana.novakova@example.test', 'phone' => '+420 602 111 222']);
        $other = $this->placeOrder([$this->offered('T-QS-OTHER', 30000) => 1], customer: ['name' => 'Petr Svoboda', 'email' => 'petr@example.test', 'phone' => '777 999 888']);
        $this->act($jana, 'confirm', self::AGREED);
        $this->act($jana, 'items/'.$this->order($jana)['items'][0]['id'].'/ordered', ['supplierReference' => 'B24-778899']);
        $reference = $this->order($jana)['reference'];

        foreach ([
            'reference' => strtolower(substr($reference, 3)), 'name' => 'nováK', 'email' => 'JANA.NOVAKOVA@', 'phone digits' => '602111222',
            'phone as typed' => '+420 602 111', 'phone part' => '111-222', 'supplier reference' => 'b24-7788',
        ] as $field => $q) {
            self::assertSame([$jana], array_column($this->getJson('/api/admin/orders', ['q' => $q])['items'], 'id'), $field);
        }
        self::assertSame([$other], array_column($this->getJson('/api/admin/orders', ['q' => '999 888'])['items'], 'id'));
        self::assertSame(0, $this->getJson('/api/admin/orders', ['q' => 'nobody-like-this'])['total']);
        // wildcards are taken literally, and two digits are no phone search
        self::assertSame(0, $this->getJson('/api/admin/orders', ['q' => '%'])['total']);
        self::assertSame(0, $this->getJson('/api/admin/orders', ['q' => '(22)'])['total']);
        // search and queue together
        self::assertSame([$jana], array_column($this->getJson('/api/admin/orders', ['q' => 'example.test', 'queue' => 'waiting'])['items'], 'id'));
    }

    /** Moves the confirmation to noon (Prague) N days ago; returns that Prague day. */
    private function confirmedDaysAgo(string $orderId, int $days): \DateTimeImmutable
    {
        $at = (new \DateTimeImmutable('now', new \DateTimeZone(OrderQueues::TIMEZONE)))->setTime(12, 0)->modify(sprintf('-%d days', $days));
        $this->db()->executeStatement('UPDATE shop_order SET confirmed_at = :at WHERE id = :id', ['at' => $at->format(\DATE_ATOM), 'id' => $orderId]);

        return $at->setTime(0, 0);
    }
}
