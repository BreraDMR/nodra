<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\OrderEvent;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Shipment;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The frame every admin change of an order runs in: lock the order row, apply the change, settle what follows from
 * it, flush. Shared by the D04 actions, the D05 corrections and purchases (which settle several orders at once).
 */
final class OrderTransaction
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private OrderLoader $loader,
        private OrderRules $rules,
        private OrderJournal $journal,
        private Loyalty $loyalty,
        private OrderPresenter $presenter,
    ) {}

    /**
     * @param callable(OrderState): void $action
     *
     * @return ?array the admin view afterwards, null for an unknown order
     */
    public function run(string $orderId, string $actor, callable $action): ?array
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
    public function settle(OrderState $s, string $actor): void
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
            // netPaidMinor is where later refunds and voids are measured from, see reconcilePoints()
            $this->journal->record($order, 'completed', [
                'goodsMinor' => $subtotal, 'points' => $points, 'refundedBeforeMinor' => $s->refundsMinor(), 'netPaidMinor' => $s->netPaidMinor(),
            ], OrderEvent::SYSTEM);
        }
    }

    /**
     * After money moved on an order that was already completed: the points follow what the customer paid at
     * completion minus what they have paid now.
     *
     * @param string $reason App\Entity\LoyaltyEntry::REFUND for a refund, ::CORRECTION for a void or a payment after one
     */
    public function reconcilePoints(OrderState $s, string $reason): void
    {
        $completed = $this->em->getRepository(OrderEvent::class)->findOneBy(['order' => $s->order, 'type' => 'completed']);
        $lost = max(0, $this->netPaidAtCompletion($s, $completed) - $s->netPaidMinor());
        $points = $this->loyalty->reconcile($s->order, $lost, $reason);
        if ($points !== 0) {
            $this->journal->record($s->order, 'loyalty_adjusted', ['points' => $points, 'reason' => $reason], OrderEvent::SYSTEM);
        }
    }

    private function netPaidAtCompletion(OrderState $s, ?OrderEvent $completed): int
    {
        $data = $completed?->getData() ?? [];
        if (isset($data['netPaidMinor'])) {
            return (int) $data['netPaidMinor'];
        }
        // completed before D05 (or before D04, without the event): nothing was voided then and every payment came
        // before completion, so it's the payments recorded up to then minus what was refunded before
        $paid = 0;
        foreach ($s->payments() as $payment) {
            if ($payment->getKind() === Payment::PAYMENT && ($completed === null || $payment->getRecordedAt() <= $completed->getCreatedAt())) {
                $paid += $payment->getAmountMinor();
            }
        }

        return $paid - (int) ($data['refundedBeforeMinor'] ?? 0);
    }
}
