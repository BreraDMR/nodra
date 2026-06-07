<?php

declare(strict_types=1);

namespace App\Purchase;

use App\Admin\CreatePurchaseRequest;
use App\Admin\PurchaseLineRequest;
use App\Entity\OrderItem;
use App\Entity\Purchase;
use App\Entity\PurchaseLine;
use App\Entity\SupplierOffer;
use App\Order\OrderActions;
use App\Order\OrderJournal;
use App\Order\OrderLoader;
use App\Order\OrderProblem;
use App\Order\OrderRules;
use App\Order\OrderState;
use App\Order\OrderStatus;
use App\Order\OrderTransaction;
use App\Order\Procurement;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recording, receiving and cancelling purchases. A purchase touches lines of several orders: every order row is
 * locked (in id order, so two purchases can't deadlock), changed through the same line methods and journal as
 * the D04 actions, and settled. Methods return the purchase view, or null for an unknown purchase.
 */
final class PurchaseService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private OrderLoader $loader,
        private OrderRules $rules,
        private OrderJournal $journal,
        private OrderTransaction $tx,
        private OrderActions $actions,
        private PurchaseQueries $queries,
    ) {}

    /** The same Idempotency-Key again returns the first purchase; with another body it's a 409. */
    public function create(CreatePurchaseRequest $request, string $key, string $actor): array
    {
        if (strlen($key) < 16 || strlen($key) > 80) {
            throw OrderProblem::unprocessable('invalid_idempotency_key', 'Idempotency-Key must contain 16 to 80 characters');
        }
        $rate = $this->rate($request);
        $lines = $this->lines($request->lines);
        $seller = self::text($request->seller);
        $note = self::text($request->note);
        $requestHash = hash('sha256', json_encode([
            $request->supplier, $seller, trim($request->reference), $request->currency, $rate, $request->fxRateDate?->format('Y-m-d'),
            $request->inboundShippingMinor, $note, $lines,
        ], JSON_THROW_ON_ERROR));

        try {
            $purchaseId = $this->db->transactional(function () use ($request, $key, $requestHash, $rate, $lines, $seller, $note, $actor): string {
                $this->db->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(:key, 2))', ['key' => $key]);
                $existing = $this->em->getRepository(Purchase::class)->findOneBy(['idempotencyKey' => $key]);
                if ($existing !== null) {
                    return $this->samePurchase($existing, $requestHash);
                }
                $states = $this->lockOrdersOf(array_keys($lines));
                $items = [];
                foreach (array_keys($lines) as $index => $itemId) {
                    $items[$itemId] = $this->purchasable($states, $itemId, $request->supplier, $index);
                }

                $purchase = new Purchase($request->supplier, $seller, trim($request->reference), $request->currency, $rate, $request->fxRateDate,
                    $request->inboundShippingMinor, $note, $actor, $key, $requestHash);
                $this->em->persist($purchase);
                $values = array_map(static fn (string $itemId): int => $lines[$itemId] * $items[$itemId]->getQuantity(), array_keys($lines));
                $shipping = Allocation::shipping($request->inboundShippingMinor, $values);
                foreach (array_keys($lines) as $index => $itemId) {
                    $item = $items[$itemId];
                    $unitCost = Allocation::unitCostCzk($lines[$itemId], $shipping[$index], $item->getQuantity(), $rate);
                    $this->em->persist(new PurchaseLine($purchase, $item, $lines[$itemId], $shipping[$index], $unitCost));
                    $item->markOrdered($purchase->getReference());
                    $this->journal->record($item->getOrder(), 'item_ordered', [
                        'itemId' => $itemId, 'sku' => $item->getSku(), 'supplierReference' => $purchase->getReference(),
                        'purchaseId' => $purchase->getId()->toRfc4122(),
                    ], $actor);
                }
                foreach ($states as $s) {
                    $this->tx->settle($s, $actor);
                }
                $this->em->flush();

                return $purchase->getId()->toRfc4122();
            });
        } catch (UniqueConstraintViolationException) {
            // a parallel request with the same key won the insert
            $this->em->clear();
            $existing = $this->em->getRepository(Purchase::class)->findOneBy(['idempotencyKey' => $key]) ?? throw new \DomainException('The purchase could not be recorded');
            $purchaseId = $this->samePurchase($existing, $requestHash);
        }
        $this->em->clear();

        return $this->queries->detail($purchaseId);
    }

    /** Every line still `ordered` is received; lines received one by one before stay as they are. */
    public function receive(string $purchaseId, string $actor): ?array
    {
        return $this->change($purchaseId, 'receive', $actor, function (Purchase $purchase, array $lines, array $states) use ($actor): void {
            foreach ($lines as $line) {
                $item = $line->getOrderItem();
                if ($item->getProcurementStatus() === Procurement::ORDERED) {
                    $this->actions->receiveGoods($states[$item->getOrder()->getId()->toRfc4122()], $item, $actor, ['purchaseId' => $purchase->getId()->toRfc4122()]);
                }
            }
            $purchase->receive();
        });
    }

    /** Before anything of it arrived: its lines are to be bought again. */
    public function cancel(string $purchaseId, string $reason, string $actor): ?array
    {
        return $this->change($purchaseId, 'cancel', $actor, function (Purchase $purchase, array $lines, array $states) use ($reason, $actor): void {
            $byOrder = [];
            foreach ($lines as $line) {
                $item = $line->getOrderItem();
                if ($item->getProcurementStatus() === Procurement::RECEIVED) {
                    throw OrderProblem::conflict('purchase_partly_received', sprintf('%s was already received; undo that first or mark the rest failed line by line', $item->getSku()), ['itemId' => $item->getId()->toRfc4122()]);
                }
                // failed lines stay failed, their replacement is bought separately
                if ($item->getProcurementStatus() === Procurement::ORDERED) {
                    $item->backToOrder();
                    $byOrder[$item->getOrder()->getId()->toRfc4122()][] = $item->getId()->toRfc4122();
                }
            }
            foreach ($byOrder as $orderId => $itemIds) {
                $this->journal->record($states[$orderId]->order, 'purchase_cancelled', [
                    'purchaseId' => $purchase->getId()->toRfc4122(), 'reference' => $purchase->getReference(), 'itemIds' => $itemIds, 'reason' => $reason,
                ], $actor);
            }
            $purchase->cancel($reason);
        });
    }

    /**
     * @param callable(Purchase, list<PurchaseLine>, array<string, OrderState>): void $change gets the orders by id
     */
    private function change(string $purchaseId, string $action, string $actor, callable $change): ?array
    {
        if (!Uuid::isValid($purchaseId)) {
            return null;
        }
        $purchaseId = strtolower($purchaseId);
        $found = $this->db->transactional(function () use ($purchaseId, $action, $actor, $change): bool {
            if ($this->db->fetchOne('SELECT id FROM purchase WHERE id = :id FOR UPDATE', ['id' => $purchaseId]) === false) {
                return false;
            }
            // the orders are locked before any of their lines is loaded
            $states = $this->lockOrdersOf($this->db->fetchFirstColumn('SELECT order_item_id FROM purchase_line WHERE purchase_id = :id', ['id' => $purchaseId]));
            $purchase = $this->em->find(Purchase::class, Uuid::fromString($purchaseId));
            if ($purchase->getStatus() !== PurchaseStatus::ORDERED) {
                throw OrderProblem::notAllowed($action, sprintf('"%s" is not allowed on a purchase that is %s', $action, $purchase->getStatus()));
            }
            $lines = $this->em->getRepository(PurchaseLine::class)->findBy(['purchase' => $purchase], ['id' => 'ASC']);
            $change($purchase, $lines, $states);
            foreach ($states as $s) {
                $this->tx->settle($s, $actor);
            }
            $this->em->flush();

            return true;
        });
        if (!$found) {
            return null;
        }
        $this->em->clear();

        return $this->queries->detail($purchaseId);
    }

    /**
     * Locks the orders of these lines and loads them after the lock, so every check sees the current state.
     *
     * @param list<string> $itemIds
     *
     * @return array<string, OrderState> by order id
     */
    private function lockOrdersOf(array $itemIds): array
    {
        $orderIds = $itemIds === [] ? [] : $this->db->fetchFirstColumn(
            'SELECT o.id FROM shop_order o WHERE o.id IN (SELECT i.order_id FROM order_item i WHERE i.id IN (:items)) ORDER BY o.id FOR UPDATE',
            ['items' => $itemIds],
            ['items' => ArrayParameterType::STRING],
        );
        $states = [];
        foreach ($orderIds as $orderId) {
            $states[$orderId] = $this->loader->load($orderId);
        }

        return $states;
    }

    /** @param array<string, OrderState> $states */
    private function purchasable(array $states, string $itemId, string $supplier, int $index): OrderItem
    {
        $item = null;
        foreach ($states as $s) {
            $item ??= $s->item($itemId);
        }
        if ($item === null) {
            throw OrderProblem::invalid(sprintf('lines[%d].itemId', $index), 'Order line not found');
        }
        $sku = $item->getSku();
        if ($item->getOrder()->getStatus() !== OrderStatus::CONFIRMED) {
            throw OrderProblem::conflict('line_not_purchasable', sprintf('%s belongs to an order that is %s; only confirmed orders are bought for', $sku, $item->getOrder()->getStatus()), ['itemId' => $itemId]);
        }
        if (!$item->isActive() || $item->getProcurementStatus() !== Procurement::TO_ORDER) {
            throw OrderProblem::conflict('line_not_purchasable', sprintf('%s is %s, not to order', $sku, $item->isActive() ? $item->getProcurementStatus() : $item->getState()), ['itemId' => $itemId]);
        }
        $offerSupplier = $item->getSupplierOffer()?->getSupplier();
        // a line without a source can go with any supplier the admin found it at
        if ($offerSupplier !== null && $offerSupplier !== $supplier) {
            throw OrderProblem::unprocessable('mixed_suppliers', sprintf('%s comes from %s, not %s; one purchase is one supplier', $sku, $offerSupplier, $supplier), ['itemId' => $itemId]);
        }
        $s = $states[$item->getOrder()->getId()->toRfc4122()];
        $this->rules->require(OrderRules::MARK_ORDERED, $this->rules->itemActions($s, $item), 'line');

        return $item;
    }

    /**
     * @param list<PurchaseLineRequest> $lines
     *
     * @return array<string, int> order item id => unit price, sorted by id
     */
    private function lines(array $lines): array
    {
        $prices = [];
        foreach ($lines as $index => $line) {
            $id = strtolower($line->itemId);
            if (isset($prices[$id])) {
                throw OrderProblem::invalid(sprintf('lines[%d].itemId', $index), 'Each order line may be sent once');
            }
            $prices[$id] = $line->unitPriceMinor;
        }
        ksort($prices);

        return $prices;
    }

    private function rate(CreatePurchaseRequest $request): int
    {
        if ($request->currency === 'CZK') {
            if ($request->fxRateCzk !== null && $request->fxRateCzk !== SupplierOffer::CZK_RATE) {
                throw OrderProblem::invalid('fxRateCzk', 'A CZK purchase has the rate 1000000 or none');
            }

            return SupplierOffer::CZK_RATE;
        }

        return $request->fxRateCzk ?? throw OrderProblem::invalid('fxRateCzk', sprintf('A %s purchase needs the rate it was paid at', $request->currency));
    }

    private function samePurchase(Purchase $purchase, string $requestHash): string
    {
        if (!hash_equals($purchase->getRequestHash(), $requestHash)) {
            throw OrderProblem::conflict('idempotency_conflict', 'Idempotency key was used with a different purchase');
        }

        return $purchase->getId()->toRfc4122();
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
