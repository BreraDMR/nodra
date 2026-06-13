<?php

declare(strict_types=1);

namespace App\Tests\Order;

use App\Entity\Category;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Shipment;
use App\Entity\ShopOrder;
use App\Order\OrderQueues;
use App\Order\OrderState;
use PHPUnit\Framework\TestCase;

/** Every queue on both sides of its condition, without a database. QueuesApiTest checks the SQL agrees. */
final class OrderQueuesTest extends TestCase
{
    private ShopOrder $order;
    private Shipment $shipment;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->order = new ShopOrder(str_repeat('k', 20), str_repeat('f', 64), 'cs', [
            'name' => 'Rider', 'email' => 'rider@example.test', 'phone' => '+420777123456', 'contactChannel' => 'phone',
            'address' => 'Demo 1', 'city' => 'Praha', 'postalCode' => '110 00', 'district' => '', 'deliveryNote' => null,
        ], 'together', 'draft-2026-09', false);
        $this->shipment = new Shipment($this->order, 1, 'prague_personal', 0);
        $this->today = new \DateTimeImmutable();
    }

    public function testReviewUntilConfirmedThenToPurchaseUntilOrdered(): void
    {
        $line = $this->line();
        $s = $this->state([$line]);
        self::assertSame(['review'], $this->queues($s), 'a requested order is checked before anything is bought');

        $this->order->confirm();
        self::assertSame(['to_purchase'], $this->queues($s));

        $line->markOrdered('SUP-1');
        self::assertSame(['waiting'], $this->queues($s));
        $line->markReceived();
        self::assertSame(['to_schedule'], $this->queues($s), 'the goods are here and nobody agreed a window yet');
    }

    public function testFailedLineIsAProblemUntilItIsCancelled(): void
    {
        $failed = $this->line();
        $failed->markFailed();
        $s = $this->state([$failed, $this->line()]);
        self::assertContains('problem', $this->queues($s));

        $failed->cancel();
        self::assertNotContains('problem', $this->queues($s));
    }

    public function testToScheduleNeedsAConfirmedOrderAndEveryLineReady(): void
    {
        $held = $this->line(fromStock: true);
        $toOrder = $this->line();
        $s = $this->state([$held, $toOrder]);
        self::assertNotContains('to_schedule', $this->queues($s), 'requested: it is in review first');

        $this->order->confirm();
        self::assertNotContains('to_schedule', $this->queues($s), 'one line still has to be bought');
        $toOrder->cancel();
        self::assertContains('to_schedule', $this->queues($s));

        $this->shipment->schedule(new \DateTimeImmutable('2026-10-01 17:00'), new \DateTimeImmutable('2026-10-01 19:00'));
        self::assertSame(['delivering'], $this->queues($s));
    }

    public function testUnpaidAfterHandoverUntilPaidAndRefundWhenMoreWasTaken(): void
    {
        $this->order->confirm();
        $line = $this->line(fromStock: true);
        $s = $this->state([$line], total: 30000);
        $s->addPayment($this->payment('payment', 10000));
        self::assertNotContains('unpaid', $this->queues($s), 'money is due only once the goods went out');

        $this->shipment->schedule(new \DateTimeImmutable('2026-10-01 17:00'), new \DateTimeImmutable('2026-10-01 19:00'));
        $this->shipment->handOver();
        self::assertSame(['unpaid'], $this->queues($s));

        $s->addPayment($this->payment('payment', 20000));
        self::assertSame([], $this->queues($s));

        $line->markReturned();
        $this->order->setTotals(0, 0);
        self::assertSame(['refund'], $this->queues($s));
        $s->addPayment($this->payment('refund', 30000));
        self::assertSame([], $this->queues($s));
    }

    public function testAVoidedPaymentIsOwedAgain(): void
    {
        $this->order->confirm();
        $s = $this->state([$this->line(fromStock: true)], total: 30000);
        $this->shipment->schedule(new \DateTimeImmutable('2026-10-01 17:00'), new \DateTimeImmutable('2026-10-01 19:00'));
        $this->shipment->handOver();
        $paid = $this->payment('payment', 30000);
        $s->addPayment($paid);
        self::assertSame([], $this->queues($s));

        $s->addPayment(Payment::voiding($paid, 'Counted twice', 'admin@nodra.test'));
        self::assertSame([0, 30000], [$s->netPaidMinor(), $s->amountDueMinor()]);
        self::assertSame(['unpaid'], $this->queues($s));
    }

    public function testDelayedFromTheDayAfterThePromisedDate(): void
    {
        $this->order->confirm();
        $line = $this->line(leadTimeMaxDays: 5);
        $line->markOrdered('SUP-1');
        $s = $this->state([$line]);
        $confirmedDay = OrderQueues::date($this->order->getConfirmedAt());

        self::assertEquals($confirmedDay->modify('+5 days'), OrderQueues::promisedDate($this->order, $line));
        $onTheDay = $confirmedDay->modify('+5 days')->setTime(23, 59);
        $dayLater = $confirmedDay->modify('+6 days')->setTime(0, 1);
        self::assertSame(['waiting'], OrderQueues::of($s, $onTheDay), 'on the promised day it is not late yet');
        self::assertFalse(OrderQueues::isDelayed($this->order, $line, $onTheDay));
        self::assertSame(['waiting', 'delayed'], OrderQueues::of($s, $dayLater));
        self::assertTrue(OrderQueues::isDelayed($this->order, $line, $dayLater));

        // received in time for nothing: once the goods are here it's no longer late
        $line->markReceived();
        self::assertFalse(OrderQueues::isDelayed($this->order, $line, $dayLater));
    }

    public function testPromisedDateIsAPragueDate(): void
    {
        // confirmed at 23:30 UTC on 30 September is 1 October in Prague
        $this->order->confirm();
        (new \ReflectionProperty(ShopOrder::class, 'confirmedAt'))->setValue($this->order, new \DateTimeImmutable('2026-09-30 23:30:00+00:00'));
        $line = $this->line(leadTimeMaxDays: 2);

        self::assertSame('2026-10-03', OrderQueues::promisedDate($this->order, $line)->format('Y-m-d'));
        $line->markOrdered('SUP-1');
        self::assertFalse(OrderQueues::isDelayed($this->order, $line, new \DateTimeImmutable('2026-10-03 21:59:00+00:00')));
        self::assertTrue(OrderQueues::isDelayed($this->order, $line, new \DateTimeImmutable('2026-10-03 22:01:00+00:00')));
    }

    public function testLinesWithoutALeadTimeOrAConfirmationAreNeverLate(): void
    {
        $unknown = $this->line(leadTimeMaxDays: null);
        $unknown->markOrdered('SUP-1');
        self::assertNull(OrderQueues::promisedDate($this->order, $unknown), 'not confirmed yet');

        $this->order->confirm();
        $farFuture = new \DateTimeImmutable('+10 years');
        self::assertNull(OrderQueues::promisedDate($this->order, $unknown));
        self::assertFalse(OrderQueues::isDelayed($this->order, $unknown, $farFuture));
        self::assertNotContains('delayed', OrderQueues::of($this->state([$unknown]), $farFuture));
    }

    /** @return list<string> */
    private function queues(OrderState $s): array
    {
        return OrderQueues::of($s, $this->today);
    }

    private function line(bool $fromStock = false, ?int $leadTimeMaxDays = 5): OrderItem
    {
        $product = new Product('t-queues', new Category('t-queues', ['cs' => 'x', 'de' => 'x', 'en' => 'x']), ['cs' => ['name' => 'x', 'short' => 'x', 'description' => 'x', 'details' => []]], '/x.png');
        $line = new OrderItem($this->order, $this->shipment, new ProductVariant($product, 'T-QUEUES', ['cs' => 'x'], 30000, 1200, 1), 'x', 'x', 1, 30000, $fromStock);
        $line->recordSourcing('orderable', $leadTimeMaxDays === null ? null : 2, $leadTimeMaxDays, null, 20000);

        return $line;
    }

    private function payment(string $kind, int $amount): Payment
    {
        return new Payment($this->order, null, $kind, 'cash', $amount, 'admin@nodra.test', null, bin2hex(random_bytes(10)), str_repeat('0', 64));
    }

    /** @param list<OrderItem> $items */
    private function state(array $items, int $total = 30000): OrderState
    {
        $this->order->setTotals($total, 0);

        return new OrderState($this->order, $items, [$this->shipment], []);
    }
}
