<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\LoyaltyEntry;
use App\Entity\Payment;
use App\Entity\Shipment;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fixing an order without touching the database by hand. Every correction needs a reason and writes the journal
 * with the admin's email; like the actions, each returns the admin view, or null for an unknown order.
 */
final class OrderCorrections
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrderRules $rules,
        private OrderJournal $journal,
        private OrderTransaction $tx,
    ) {}

    /**
     * Moves a line to another planned or scheduled shipment of the order, or to a new part when $shipmentId is null.
     * The owner splits for his own reasons, so a new part is free; a shipment left empty is cancelled with its fee.
     */
    public function moveLine(string $orderId, string $itemId, ?string $shipmentId, string $reason, string $actor): ?array
    {
        return $this->tx->run($orderId, $actor, function (OrderState $s) use ($itemId, $shipmentId, $reason, $actor): void {
            $item = $s->item($itemId) ?? throw OrderProblem::notFound('Order line not found');
            $this->rules->require(OrderRules::MOVE, $this->rules->itemCorrections($s, $item), 'line');
            $from = $item->getShipment();
            if ($shipmentId === null) {
                $position = max(array_map(static fn (Shipment $shipment): int => $shipment->getPosition(), $s->shipments())) + 1;
                $to = new Shipment($s->order, $position, $from->getMethod(), 0);
                $this->em->persist($to);
                $s->addShipment($to);
            } else {
                $to = $s->shipment($shipmentId) ?? throw OrderProblem::notFound('Shipment not found');
                if ($to->getId()->equals($from->getId())) {
                    throw OrderProblem::invalid('shipmentId', 'The line is already in this shipment');
                }
                if (!in_array($to->getStatus(), [ShipmentStatus::PLANNED, ShipmentStatus::SCHEDULED], true)) {
                    throw OrderProblem::conflict('shipment_closed', sprintf('Shipment %d is %s; lines move only into a planned or scheduled one', $to->getPosition(), $to->getStatus()));
                }
            }
            $item->moveTo($to);
            $this->journal->record($s->order, 'item_moved', [
                'itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(),
                'fromShipmentId' => $from->getId()->toRfc4122(), 'fromNumber' => $from->getPosition(),
                'toShipmentId' => $to->getId()->toRfc4122(), 'toNumber' => $to->getPosition(), 'newPart' => $shipmentId === null,
                'reason' => $reason,
            ], $actor);
        });
    }

    public function reschedule(string $orderId, string $shipmentId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $reason, string $actor): ?array
    {
        return $this->tx->run($orderId, $actor, function (OrderState $s) use ($shipmentId, $from, $to, $reason, $actor): void {
            $shipment = $s->shipment($shipmentId) ?? throw OrderProblem::notFound('Shipment not found');
            $this->rules->require(OrderRules::RESCHEDULE, $this->rules->shipmentCorrections($s, $shipment), 'shipment');
            if ($to <= $from) {
                throw OrderProblem::invalid('to', 'The window must end after it starts');
            }
            $before = ['from' => $shipment->getScheduledFrom()?->format(\DATE_ATOM), 'to' => $shipment->getScheduledTo()?->format(\DATE_ATOM)];
            $shipment->schedule($from, $to);
            $this->journal->record($s->order, 'shipment_rescheduled', [
                'shipmentId' => $shipment->getId()->toRfc4122(), 'number' => $shipment->getPosition(),
                'before' => $before, 'after' => ['from' => $from->format(\DATE_ATOM), 'to' => $to->format(\DATE_ATOM)], 'reason' => $reason,
            ], $actor);
        });
    }

    /** A mistaken "received": the line is `ordered` again while its shipment isn't handed over. */
    public function undoReceived(string $orderId, string $itemId, string $reason, string $actor): ?array
    {
        return $this->tx->run($orderId, $actor, function (OrderState $s) use ($itemId, $reason, $actor): void {
            $item = $s->item($itemId) ?? throw OrderProblem::notFound('Order line not found');
            $this->rules->require(OrderRules::UNDO_RECEIVED, $this->rules->itemCorrections($s, $item), 'line');
            $item->undoReceived();
            $this->journal->record($s->order, 'item_receipt_undone', ['itemId' => $item->getId()->toRfc4122(), 'sku' => $item->getSku(), 'reason' => $reason], $actor);
        });
    }

    /**
     * Voids a payment or a refund with a `correction` entry that cancels its amount; the ledger stays append-only and
     * an entry is voided once. A payment can't be voided below zero received: void the refunds that followed it first.
     * On a completed order the points follow the money (see OrderTransaction::reconcilePoints); the order stays
     * completed and shows in the unpaid queue when money is owed again.
     */
    public function void(string $orderId, string $paymentId, string $reason, string $actor): ?array
    {
        return $this->tx->run($orderId, $actor, function (OrderState $s) use ($paymentId, $reason, $actor): void {
            $entry = $s->payment($paymentId) ?? throw OrderProblem::notFound('Ledger entry not found');
            $this->rules->require(OrderRules::VOID, $this->rules->paymentCorrections($s, $entry), 'ledger entry');
            if ($entry->getKind() === Payment::PAYMENT && $entry->getAmountMinor() > $s->netPaidMinor()) {
                throw OrderProblem::unprocessable('void_exceeds_paid', sprintf('Voiding it would leave less than nothing received (%d); void the refunds first', $s->netPaidMinor()), ['paidMinor' => $s->netPaidMinor()]);
            }
            $completedBefore = $s->order->getStatus() === OrderStatus::COMPLETED;
            $correction = Payment::voiding($entry, $reason, $actor);
            $this->em->persist($correction);
            $s->addPayment($correction);
            $this->journal->record($s->order, 'entry_voided', [
                'paymentId' => $correction->getId()->toRfc4122(), 'voidedId' => $entry->getId()->toRfc4122(),
                'kind' => $entry->getKind(), 'method' => $entry->getMethod(), 'amountMinor' => $entry->getAmountMinor(), 'reason' => $reason,
            ], $actor);
            if ($completedBefore) {
                $this->tx->reconcilePoints($s, LoyaltyEntry::CORRECTION);
            }
        });
    }
}
