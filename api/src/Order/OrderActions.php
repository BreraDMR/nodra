<?php

declare(strict_types=1);

namespace App\Order;

use App\Checkout\BasketLoader;
use App\Entity\OrderEvent;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\ProductVariant;
use App\Entity\Shipment;
use App\Entity\SupplierOffer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What the admin does with an order, from confirmation to paid handover. Each action locks the order, checks
 * OrderRules, applies its stock effects, writes the journal, recomputes totals and payment status, and completes
 * the order when every active line is delivered and paid. Actions return the admin view, or null for an unknown order.
 */
final class OrderActions
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private OrderLoader $loader,
        private OrderRules $rules,
        private OrderJournal $journal,
        private StockKeeper $stock,
        private Loyalty $loyalty,
        private PaymentSettings $paymentSettings,
        private BasketLoader $basket,
        private OrderPresenter $presenter,
    ) {}

    /** @param array{channel: string, note: string} $agreement */
    public function confirm(string $orderId, array $agreement, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($agreement, $actor): void {
            $this->rules->require(OrderRules::CONFIRM, $this->rules->orderActions($s), 'order');
            $s->order->confirm();
            $this->journal->record($s->order, 'confirmed', ['customerAgreedVia' => $agreement], $actor);
        });
    }

    public function cancel(string $orderId, string $reason, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($reason, $actor): void {
            $this->rules->require(OrderRules::CANCEL, $this->rules->orderActions($s), 'order');
            $cancelled = [];
            foreach ($s->activeItems() as $item) {
                $this->release($s, $item, 'order cancelled');
                $item->cancel();
                $cancelled[] = $item->getId()->toRfc4122();
            }
            foreach ($s->shipments() as $shipment) {
                if ($shipment->getStatus() !== ShipmentStatus::HANDED_OVER && $shipment->getStatus() !== ShipmentStatus::CANCELLED) {
                    $shipment->cancel();
                }
            }
            $s->order->cancel();
            $this->journal->record($s->order, 'cancelled', ['reason' => $reason, 'itemIds' => $cancelled], $actor);
        });
    }

    /** @param ?array{channel: string, note: string} $agreement */
    public function changeTerms(string $orderId, string $itemId, ?int $unitPriceMinor, ?int $leadTimeMinDays, ?int $leadTimeMaxDays, ?array $agreement, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($itemId, $unitPriceMinor, $leadTimeMinDays, $leadTimeMaxDays, $agreement, $actor): void {
            $item = $this->item($s, $itemId, OrderRules::CHANGE_TERMS);
            if (($leadTimeMinDays === null) !== ($leadTimeMaxDays === null)) {
                throw OrderProblem::invalid('leadTimeMaxDays', 'Send the minimum and the maximum lead time together');
            }
            if ($unitPriceMinor === null && $leadTimeMinDays === null) {
                throw OrderProblem::invalid('unitPriceMinor', 'Send a new unit price, a new lead time or both');
            }
            $before = self::terms($item);
            if ($unitPriceMinor !== null) {
                $item->changePrice($unitPriceMinor);
            }
            if ($leadTimeMinDays !== null) {
                try {
                    $item->changeLeadTime($leadTimeMinDays, $leadTimeMaxDays);
                } catch (\InvalidArgumentException $error) {
                    throw OrderProblem::invalid('leadTimeMinDays', $error->getMessage());
                }
            }
            // the customer agreed to the old terms only
            $reopened = $s->order->getStatus() === OrderStatus::CONFIRMED;
            if ($reopened) {
                $s->order->reopen();
            }
            $this->journal->record($s->order, 'terms_changed', [
                'itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(), 'before' => $before, 'after' => self::terms($item),
                'customerAgreedVia' => $agreement, 'reopened' => $reopened,
            ], $actor);
        });
    }

    public function markOrdered(string $orderId, string $itemId, string $supplierReference, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($itemId, $supplierReference, $actor): void {
            $item = $this->item($s, $itemId, OrderRules::MARK_ORDERED);
            $item->markOrdered($supplierReference);
            $this->journal->record($s->order, 'item_ordered', ['itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(), 'supplierReference' => $supplierReference], $actor);
        });
    }

    public function markReceived(string $orderId, string $itemId, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($itemId, $actor): void {
            $item = $this->item($s, $itemId, OrderRules::MARK_RECEIVED);
            $item->markReceived();
            // the line was cancelled while the goods were on their way: they are NODRA's own stock now
            $toStock = !$item->isActive() && $item->getVariant() !== null;
            if ($toStock) {
                $this->stock->put($item->getVariant()->getId(), $item->getQuantity(), $s->order, 'goods of a cancelled line received');
            }
            $this->journal->record($s->order, 'item_received', ['itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(), 'toOwnStock' => $toStock], $actor);
        });
    }

    public function markFailed(string $orderId, string $itemId, string $reason, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($itemId, $reason, $actor): void {
            $item = $this->item($s, $itemId, OrderRules::MARK_FAILED);
            $item->markFailed();
            $this->journal->record($s->order, 'item_failed', ['itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(), 'reason' => $reason], $actor);
        });
    }

    public function cancelItem(string $orderId, string $itemId, string $reason, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($itemId, $reason, $actor): void {
            $item = $this->item($s, $itemId, OrderRules::CANCEL_LINE);
            $this->release($s, $item, 'line cancelled');
            $item->cancel();
            $this->journal->record($s->order, 'item_cancelled', ['itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(), 'reason' => $reason], $actor);
        });
    }

    /** @param array{channel: string, note: string} $agreement */
    public function replace(string $orderId, string $itemId, string $variantId, ?int $quantity, ?int $unitPriceMinor, array $agreement, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($itemId, $variantId, $quantity, $unitPriceMinor, $agreement, $actor): void {
            $failed = $this->item($s, $itemId, OrderRules::REPLACE);
            $quantity ??= $failed->getQuantity();
            $line = $this->basket->lines([strtolower($variantId) => $quantity], $s->order->getLocale(), lock: true, publishedOnly: false)[0];
            if ($line->isUnavailable()) {
                throw OrderProblem::unprocessable('unavailable', sprintf('%s (%s) is not available from any supplier', $line->name, $line->label), ['variantId' => $line->variantId]);
            }
            $variant = $this->em->find(ProductVariant::class, Uuid::fromString($line->variantId));
            $replacement = new OrderItem($s->order, $failed->getShipment(), $variant, $line->name, $line->label, $quantity, $unitPriceMinor ?? $line->unitPriceMinor, $line->fromStock);
            $source = $line->sourcing;
            $offer = $source->offer === null ? null : $this->em->getReference(SupplierOffer::class, Uuid::fromString($source->offer->id));
            $replacement->recordSourcing($source->status, $source->leadTimeMinDays, $source->leadTimeMaxDays, $offer, $source->landedCostCzk);
            $replacement->replaces($failed);
            $this->em->persist($replacement);
            $s->addItem($replacement);
            if ($line->fromStock && !$this->stock->take($variant->getId(), $quantity, $s->order, 'reserved for a replacement')) {
                throw OrderProblem::conflict('not_enough_stock', 'Own stock no longer covers the replacement');
            }
            $failed->cancel();
            $this->journal->record($s->order, 'replacement_added', [
                'itemId' => $replacement->getId()->toRfc4122(), 'replacesItemId' => $failed->getId()->toRfc4122(),
                'sku' => $replacement->getSku(), 'quantity' => $quantity, 'unitPriceMinor' => $replacement->getUnitPriceMinor(),
                'procurementStatus' => $replacement->getProcurementStatus(),
                'customerAgreedVia' => $agreement,
            ], $actor);
        });
    }

    public function returnItem(string $orderId, string $itemId, string $reason, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($itemId, $reason, $actor): void {
            $item = $this->item($s, $itemId, OrderRules::RETURN);
            $item->markReturned();
            if ($item->getVariant() !== null) {
                $this->stock->put($item->getVariant()->getId(), $item->getQuantity(), $s->order, 'returned after handover');
            }
            $this->journal->record($s->order, 'item_returned', ['itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(), 'reason' => $reason], $actor);
        });
    }

    public function schedule(string $orderId, string $shipmentId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($shipmentId, $from, $to, $actor): void {
            $shipment = $this->shipment($s, $shipmentId, OrderRules::SCHEDULE);
            if ($to <= $from) {
                throw OrderProblem::invalid('to', 'The window must end after it starts');
            }
            // a refused shipment gave its goods to own stock; planning it again takes them back
            $replanned = $shipment->getStatus() === ShipmentStatus::REFUSED;
            if ($replanned) {
                foreach ($s->activeItemsOf($shipment) as $item) {
                    if ($item->getVariant() === null || !$this->stock->take($item->getVariant()->getId(), $item->getQuantity(), $s->order, 'taken again for a re-planned shipment')) {
                        throw OrderProblem::conflict('not_enough_stock', sprintf('Own stock no longer has %d x %s for this shipment', $item->getQuantity(), $item->getSku()));
                    }
                    $item->takeFromStock();
                }
            }
            $shipment->schedule($from, $to);
            $this->journal->record($s->order, 'shipment_scheduled', [
                'shipmentId' => $shipment->getId()->toRfc4122(), 'number' => $shipment->getPosition(),
                'from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM), 'replanned' => $replanned,
            ], $actor);
        });
    }

    public function handOver(string $orderId, string $shipmentId, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($shipmentId, $actor): void {
            $shipment = $this->shipment($s, $shipmentId, OrderRules::HAND_OVER);
            $shipment->handOver();
            $this->journal->record($s->order, 'shipment_handed_over', ['shipmentId' => $shipment->getId()->toRfc4122(), 'number' => $shipment->getPosition()], $actor);
        });
    }

    public function refuse(string $orderId, string $shipmentId, string $reason, string $actor): ?array
    {
        return $this->run($orderId, $actor, function (OrderState $s) use ($shipmentId, $reason, $actor): void {
            $shipment = $this->shipment($s, $shipmentId, OrderRules::REFUSE);
            foreach ($s->activeItemsOf($shipment) as $item) {
                if ($item->getVariant() !== null) {
                    $this->stock->put($item->getVariant()->getId(), $item->getQuantity(), $s->order, sprintf('shipment %d refused', $shipment->getPosition()));
                }
            }
            $shipment->refuse();
            $this->journal->record($s->order, 'shipment_refused', ['shipmentId' => $shipment->getId()->toRfc4122(), 'number' => $shipment->getPosition(), 'reason' => $reason], $actor);
        });
    }

    /**
     * Appends a payment or a refund. The same key again returns the first entry and writes nothing.
     *
     * @return ?array{payment: array, order: array}
     */
    public function recordPayment(string $orderId, string $key, string $kind, string $method, int $amountMinor, ?string $shipmentId, ?string $note, string $actor): ?array
    {
        if (strlen($key) < 16 || strlen($key) > 80) {
            throw OrderProblem::unprocessable('invalid_idempotency_key', 'Idempotency-Key must contain 16 to 80 characters');
        }
        $requestHash = hash('sha256', json_encode([strtolower($orderId), $kind, $method, $amountMinor, $shipmentId === null ? null : strtolower($shipmentId), $note], JSON_THROW_ON_ERROR));

        try {
            $paymentId = $this->db->transactional(function () use ($orderId, $key, $requestHash, $kind, $method, $amountMinor, $shipmentId, $note, $actor): ?string {
                $this->db->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(:key, 1))', ['key' => $key]);
                $existing = $this->em->getRepository(Payment::class)->findOneBy(['idempotencyKey' => $key]);
                if ($existing !== null) {
                    return $this->samePayment($existing, $requestHash);
                }
                $s = $this->loader->load($orderId, lock: true);
                if ($s === null) {
                    return null;
                }
                $payment = $this->appendPayment($s, $key, $requestHash, $kind, $method, $amountMinor, $shipmentId, $note, $actor);
                $this->em->flush();

                return $payment->getId()->toRfc4122();
            });
        } catch (UniqueConstraintViolationException) {
            $this->em->clear();
            $existing = $this->em->getRepository(Payment::class)->findOneBy(['idempotencyKey' => $key]) ?? throw new \DomainException('The payment could not be recorded');
            $paymentId = $this->samePayment($existing, $requestHash);
        }
        if ($paymentId === null) {
            return null;
        }
        $this->em->clear();
        $payment = $this->em->find(Payment::class, Uuid::fromString($paymentId));

        return ['payment' => $this->presenter->payment($payment), 'order' => $this->presenter->admin($payment->getOrder()->getId()->toRfc4122())];
    }

    private function appendPayment(OrderState $s, string $key, string $requestHash, string $kind, string $method, int $amountMinor, ?string $shipmentId, ?string $note, string $actor): Payment
    {
        $refund = $kind === Payment::REFUND;
        $this->rules->require($refund ? OrderRules::RECORD_REFUND : OrderRules::RECORD_PAYMENT, $this->rules->orderActions($s), 'order');
        if (!$this->paymentSettings->accepts($kind, $method)) {
            throw OrderProblem::unprocessable('method_not_accepted', sprintf('%s is not accepted for a %s', $method, $kind));
        }
        $shipment = null;
        if ($shipmentId !== null) {
            $shipment = $s->shipment($shipmentId) ?? throw OrderProblem::notFound('Shipment not found in this order');
        }
        if (!$refund && $amountMinor > $s->amountDueMinor()) {
            throw OrderProblem::unprocessable('overpayment', sprintf('The amount is above what is still due (%d)', $s->amountDueMinor()), ['amountDueMinor' => $s->amountDueMinor()]);
        }
        if ($refund && $amountMinor > $s->netPaidMinor()) {
            throw OrderProblem::unprocessable('refund_exceeds_paid', sprintf('The refund is above what was paid (%d)', $s->netPaidMinor()), ['paidMinor' => $s->netPaidMinor()]);
        }
        $completedBefore = $s->order->getStatus() === OrderStatus::COMPLETED;
        $payment = new Payment($s->order, $shipment, $kind, $method, $amountMinor, $actor, $note, $key, $requestHash);
        $this->em->persist($payment);
        $s->addPayment($payment);
        $this->journal->record($s->order, $refund ? 'refund_recorded' : 'payment_recorded', [
            'paymentId' => $payment->getId()->toRfc4122(), 'method' => $method, 'amountMinor' => $amountMinor,
            'shipmentId' => $shipment?->getId()->toRfc4122(), 'note' => $note,
        ], $actor);
        $this->settle($s, $actor);
        if ($refund && $completedBefore) {
            $this->takeBackPoints($s);
        }

        return $payment;
    }

    /** Points for money refunded after completion go back; the completion event knows what was refunded before. */
    private function takeBackPoints(OrderState $s): void
    {
        $completed = $this->em->getRepository(OrderEvent::class)->findOneBy(['order' => $s->order, 'type' => 'completed']);
        $refundedBefore = (int) ($completed?->getData()['refundedBeforeMinor'] ?? 0);
        $points = $this->loyalty->afterRefund($s->order, $s->refundsMinor() - $refundedBefore);
        if ($points !== 0) {
            $this->journal->record($s->order, 'loyalty_adjusted', ['points' => $points], OrderEvent::SYSTEM);
        }
    }

    private function samePayment(Payment $payment, string $requestHash): string
    {
        if (!hash_equals($payment->getRequestHash(), $requestHash)) {
            throw OrderProblem::conflict('idempotency_conflict', 'Idempotency key was used with a different payment');
        }

        return $payment->getId()->toRfc4122();
    }

    /** @param callable(OrderState): void $action */
    private function run(string $orderId, string $actor, callable $action): ?array
    {
        $done = $this->db->transactional(function () use ($orderId, $actor, $action): bool {
            $s = $this->loader->load($orderId, lock: true);
            if ($s === null) {
                return false;
            }
            $action($s);
            $this->settle($s, $actor);
            $this->em->flush();

            return true;
        });
        if (!$done) {
            return null;
        }
        $this->em->clear();

        return $this->presenter->admin($orderId);
    }

    /** Everything that follows from the lines, shipments and ledger, after any change. */
    private function settle(OrderState $s, string $actor): void
    {
        $order = $s->order;
        foreach ($s->shipments() as $shipment) {
            if (in_array($shipment->getStatus(), [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED, ShipmentStatus::REFUSED], true) && $s->activeItemsOf($shipment) === []) {
                $shipment->cancel();
                $this->journal->record($order, 'shipment_cancelled', ['shipmentId' => $shipment->getId()->toRfc4122(), 'number' => $shipment->getPosition(), 'reason' => 'no active lines left'], $actor);
            }
        }
        if (in_array($order->getStatus(), [OrderStatus::REQUESTED, OrderStatus::CONFIRMED], true) && $s->activeItems() === []) {
            $order->cancel();
            $this->journal->record($order, 'cancelled', ['reason' => 'no active lines left'], $actor);
        }

        // fees were fixed at checkout: a cancelled shipment drops its fee, nothing ever raises one
        $subtotal = array_sum(array_map(static fn (OrderItem $item): int => $item->getLineTotalMinor(), $s->activeItems()));
        $shipping = array_sum(array_map(static fn (Shipment $shipment): int => $shipment->isCharged() ? $shipment->getFeeMinor() : 0, $s->shipments()));
        $order->setTotals($subtotal, $shipping);
        $order->setPaymentStatus(PaymentStatus::of($s->paymentsMinor(), $s->refundsMinor(), $order->getTotalMinor()));

        if ($this->rules->shouldComplete($s)) {
            $order->complete();
            $points = $this->loyalty->earn($order);
            $this->journal->record($order, 'completed', [
                'goodsMinor' => $subtotal, 'points' => $points, 'refundedBeforeMinor' => $s->refundsMinor(),
            ], OrderEvent::SYSTEM);
        }
    }

    /** Stock effects of cancelling a line that wasn't handed over. */
    private function release(OrderState $s, OrderItem $item, string $why): void
    {
        // a refused shipment already gave its goods to own stock; lines still to order or ordered have nothing yet
        if ($item->getShipment()->getStatus() === ShipmentStatus::REFUSED || !$item->hasGoods() || $item->getVariant() === null) {
            return;
        }
        $this->stock->put($item->getVariant()->getId(), $item->getQuantity(), $s->order, $why);
    }

    private function item(OrderState $s, string $itemId, string $action): OrderItem
    {
        $item = $s->item($itemId) ?? throw OrderProblem::notFound('Order line not found');
        $this->rules->require($action, $this->rules->itemActions($s, $item), 'line');

        return $item;
    }

    private function shipment(OrderState $s, string $shipmentId, string $action): Shipment
    {
        $shipment = $s->shipment($shipmentId) ?? throw OrderProblem::notFound('Shipment not found');
        $this->rules->require($action, $this->rules->shipmentActions($s, $shipment), 'shipment');

        return $shipment;
    }

    /** @return array{unitPriceMinor: int, leadTimeMinDays: ?int, leadTimeMaxDays: ?int} */
    private static function terms(OrderItem $item): array
    {
        return ['unitPriceMinor' => $item->getUnitPriceMinor(), 'leadTimeMinDays' => $item->getLeadTimeMinDays(), 'leadTimeMaxDays' => $item->getLeadTimeMaxDays()];
    }
}
