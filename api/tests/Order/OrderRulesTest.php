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
use App\Order\OrderRules;
use App\Order\OrderState;
use PHPUnit\Framework\TestCase;

/** Which actions each state allows, without a database. The API tests check that refused ones really are refused. */
final class OrderRulesTest extends TestCase
{
    private OrderRules $rules;
    private ShopOrder $order;
    private Shipment $shipment;

    protected function setUp(): void
    {
        $this->rules = new OrderRules();
        $this->order = new ShopOrder(str_repeat('k', 20), str_repeat('f', 64), 'cs', [
            'name' => 'Rider', 'email' => 'rider@example.test', 'phone' => '+420777123456', 'contactChannel' => 'phone',
            'address' => 'Demo 1', 'city' => 'Praha', 'postalCode' => '110 00', 'district' => '', 'deliveryNote' => null,
        ], 'together', 'draft-2026-09', false);
        $this->shipment = new Shipment($this->order, 1, 'prague_personal', 0);
    }

    public function testRequestedOrderIsCheckedAndAgreedBeforeAnythingIsBoughtOrDelivered(): void
    {
        $line = $this->line();
        $s = $this->state([$line], total: 60000);

        self::assertSame(['confirm', 'cancel', 'record_payment'], $this->rules->orderActions($s));
        self::assertSame(['change_terms', 'mark_failed', 'cancel'], $this->rules->itemActions($s, $line));
        self::assertSame([], $this->rules->shipmentActions($s, $this->shipment));
    }

    public function testFailedLineBlocksConfirmationUntilItIsReplacedOrCancelled(): void
    {
        $line = $this->line();
        $line->markFailed();
        $s = $this->state([$line, $this->line()], total: 60000);

        self::assertNotContains('confirm', $this->rules->orderActions($s));
        self::assertSame(['change_terms', 'cancel', 'replace'], $this->rules->itemActions($s, $line));
    }

    public function testConfirmedOrderBuysSchedulesAndHandsOverOnlyWhatIsReady(): void
    {
        $this->order->confirm();
        $toOrder = $this->line();
        $held = $this->line(fromStock: true);
        $s = $this->state([$toOrder, $held], total: 60000);

        self::assertSame(['cancel', 'record_payment'], $this->rules->orderActions($s));
        self::assertSame(['change_terms', 'mark_ordered', 'mark_failed', 'cancel'], $this->rules->itemActions($s, $toOrder));
        self::assertSame(['change_terms', 'cancel'], $this->rules->itemActions($s, $held));
        self::assertSame(['schedule'], $this->rules->shipmentActions($s, $this->shipment));

        $toOrder->markOrdered('SUP-1');
        self::assertSame(['change_terms', 'mark_received', 'mark_failed', 'cancel'], $this->rules->itemActions($s, $toOrder));
        $this->shipment->schedule(new \DateTimeImmutable('2026-10-01 17:00'), new \DateTimeImmutable('2026-10-01 19:00'));
        self::assertFalse($s->isReady($this->shipment));
        self::assertSame(['schedule'], $this->rules->shipmentActions($s, $this->shipment));

        $toOrder->markReceived();
        self::assertTrue($s->isReady($this->shipment));
        self::assertSame(['schedule', 'hand_over', 'refuse'], $this->rules->shipmentActions($s, $this->shipment));
    }

    public function testAfterHandoverOnlyReturnsAndMoneyRemain(): void
    {
        $this->order->confirm();
        $line = $this->line(fromStock: true);
        $this->shipment->schedule(new \DateTimeImmutable('2026-10-01 17:00'), new \DateTimeImmutable('2026-10-01 19:00'));
        $this->shipment->handOver();
        $s = $this->state([$line], total: 60000);

        self::assertSame(['record_payment'], $this->rules->orderActions($s));
        self::assertSame(['return'], $this->rules->itemActions($s, $line));
        self::assertSame([], $this->rules->shipmentActions($s, $this->shipment));
        self::assertFalse($this->rules->shouldComplete($s));

        $s->addPayment(new Payment($this->order, $this->shipment, 'payment', 'cash', 60000, 'admin@nodra.test', null, str_repeat('p', 20), str_repeat('0', 64)));
        self::assertTrue($this->rules->shouldComplete($s));
        self::assertSame(['record_refund'], $this->rules->orderActions($s));
    }

    public function testCancelledOrderOnlyTakesInGoodsStillOnTheirWayAndGivesMoneyBack(): void
    {
        $ordered = $this->line();
        $ordered->markOrdered('SUP-2');
        $s = $this->state([$ordered], total: 60000);
        $s->addPayment(new Payment($this->order, null, 'payment', 'bank_transfer', 20000, 'admin@nodra.test', null, str_repeat('q', 20), str_repeat('0', 64)));
        $ordered->cancel();
        $this->order->cancel();

        self::assertSame(['record_refund'], $this->rules->orderActions($s));
        self::assertSame(['mark_received'], $this->rules->itemActions($s, $ordered));
        self::assertSame([], $this->rules->shipmentActions($s, $this->shipment));
    }

    private function line(bool $fromStock = false): OrderItem
    {
        $product = new Product('t-rules', new Category('t-rules', ['cs' => 'x', 'de' => 'x', 'en' => 'x']), ['cs' => ['name' => 'x', 'short' => 'x', 'description' => 'x', 'details' => []]], '/x.png');

        return new OrderItem($this->order, $this->shipment, new ProductVariant($product, 'T-RULES', ['cs' => 'x'], 30000, 1200, 1), 'x', 'x', 1, 30000, $fromStock);
    }

    /** @param list<OrderItem> $items */
    private function state(array $items, int $total): OrderState
    {
        $this->order->setTotals($total, 0);

        return new OrderState($this->order, $items, [$this->shipment], []);
    }
}
