<?php

declare(strict_types=1);

namespace App\Order;

use App\Delivery\DeliveryMethod;
use App\Delivery\DeliverySettings;
use App\Entity\OrderEvent;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\ReturnClaim;
use App\Entity\Shipment;
use App\Purchase\PurchaseStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * JSON shapes of an order. The public receipt never carries cost, supplier, offer, procurement, purchase or journal
 * data; the admin view has everything, economics included.
 */
final class OrderPresenter
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrderLoader $loader,
        private OrderRules $rules,
        private DeliverySettings $delivery,
        private PaymentSettings $payment,
        private Connection $db,
        private ClockInterface $clock,
    ) {}

    public function receipt(OrderState $s): array
    {
        $order = $s->order;
        $price = fn (int $amount): array => ['amount' => $amount, 'currency' => $order->getCurrency()];
        $hasPickup = array_filter($s->shipments(), static fn (Shipment $shipment): bool => $shipment->getMethod() === DeliveryMethod::PICKUP_ANDEL) !== [];

        return [
            'reference' => $order->getReference(),
            'status' => $order->getStatus(),
            'paymentStatus' => $order->getPaymentStatus(),
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            'fulfilment' => $order->getFulfilment(),
            // how NODRA will get in touch, as the customer chose it; null on orders placed before D04
            'contactChannel' => $order->getContactChannel(),
            // the methods the shop takes on handover today, so the receipt only promises what is switched on
            'paymentMethods' => $this->payment->enabled(Payment::PAYMENT),
            'items' => array_map(static fn (OrderItem $item): array => [
                'name' => $item->getProductName(), 'variant' => $item->getVariantLabel(),
                'sku' => $item->getSku(), 'quantity' => $item->getQuantity(),
                'unitPrice' => $item->getUnitPriceMinor(), 'lineTotal' => $item->getLineTotalMinor(),
                'state' => $item->getState(),
                'leadTimeMinDays' => $item->getLeadTimeMinDays(), 'leadTimeMaxDays' => $item->getLeadTimeMaxDays(),
                'shipment' => $item->getShipment()->getPosition(),
            ], $s->items()),
            'shipments' => array_map(function (Shipment $shipment) use ($s, $price): array {
                [$min, $max] = $s->leadTime($shipment);

                return [
                    'number' => $shipment->getPosition(), 'method' => $shipment->getMethod(), 'status' => $shipment->getStatus(),
                    'fee' => $price($shipment->getFeeMinor()), 'leadTimeMinDays' => $min, 'leadTimeMaxDays' => $max,
                    'scheduledFrom' => $shipment->getScheduledFrom()?->format(\DATE_ATOM), 'scheduledTo' => $shipment->getScheduledTo()?->format(\DATE_ATOM),
                ];
            }, $s->shipments()),
            'pickupNote' => $hasPickup ? $this->delivery->pickupNote($order->getLocale()) : null,
            'subtotal' => $price($order->getSubtotalMinor()),
            'shipping' => $price($order->getShippingMinor()),
            'total' => $price($order->getTotalMinor()),
            'paid' => $price($s->netPaidMinor()),
            'amountDue' => $price($s->amountDueMinor()),
            'lookupToken' => $order->getLookupToken(),
        ];
    }

    public function admin(string $orderId): ?array
    {
        $s = $this->loader->load($orderId);
        if ($s === null) {
            return null;
        }
        $order = $s->order;
        $price = fn (int $amount): array => ['amount' => $amount, 'currency' => $order->getCurrency()];
        $events = $this->em->getRepository(OrderEvent::class)->findBy(['order' => $order], ['createdAt' => 'ASC', 'id' => 'ASC']);
        $today = $this->clock->now();
        $purchases = $this->purchasesOf($orderId);
        $delayed = array_filter($s->items(), static fn (OrderItem $item): bool => OrderQueues::isDelayed($order, $item, $today)) !== [];

        return [
            'id' => $order->getId()->toRfc4122(),
            'reference' => $order->getReference(),
            'status' => $order->getStatus(),
            'paymentStatus' => $order->getPaymentStatus(),
            'locale' => $order->getLocale(),
            'currency' => $order->getCurrency(),
            'fulfilment' => $order->getFulfilment(),
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            'confirmedAt' => $order->getConfirmedAt()?->format(\DATE_ATOM),
            'queues' => OrderQueues::of($s, $today),
            'delayed' => $delayed,
            'customer' => [
                'name' => $order->getCustomerName(), 'email' => $order->getEmail(), 'phone' => $order->getPhone(),
                'contactChannel' => $order->getContactChannel(), 'country' => $order->getCountry(), 'address' => $order->getAddress(),
                'city' => $order->getCity(), 'postalCode' => $order->getPostalCode(), 'district' => $order->getDistrict(),
                'deliveryNote' => $order->getDeliveryNote(), 'accountId' => $order->getAccount()?->getId()->toRfc4122(),
            ],
            'consents' => [
                'privacyConsentedAt' => $order->getPrivacyConsentedAt()?->format(\DATE_ATOM),
                'privacyTextVersion' => $order->getPrivacyTextVersion(),
                'marketing' => $order->hasMarketingConsent(),
                'marketingConsentedAt' => $order->getMarketingConsentedAt()?->format(\DATE_ATOM),
            ],
            'items' => array_map(function (OrderItem $item) use ($s, $order, $today, $purchases): array {
                $offer = $item->getSupplierOffer();
                $purchase = $purchases[$item->getId()->toRfc4122()] ?? null;

                return [
                    'id' => $item->getId()->toRfc4122(), 'variantId' => $item->getVariant()?->getId()->toRfc4122(),
                    'name' => $item->getProductName(), 'variant' => $item->getVariantLabel(), 'sku' => $item->getSku(),
                    'mpn' => $item->getVariant()?->getMpn(), 'ean' => $item->getVariant()?->getEan(),
                    'quantity' => $item->getQuantity(), 'unitPrice' => $item->getUnitPriceMinor(), 'lineTotal' => $item->getLineTotalMinor(),
                    'state' => $item->getState(), 'procurementStatus' => $item->getProcurementStatus(),
                    'supplierReference' => $item->getSupplierReference(), 'shipmentId' => $item->getShipment()->getId()->toRfc4122(),
                    'replacesItemId' => $item->getReplacesItem()?->getId()->toRfc4122(),
                    'availabilityStatus' => $item->getAvailabilityStatus(),
                    'leadTimeMinDays' => $item->getLeadTimeMinDays(), 'leadTimeMaxDays' => $item->getLeadTimeMaxDays(),
                    'supplierOfferId' => $offer?->getId()->toRfc4122(),
                    'offer' => $offer === null ? null : [
                        'id' => $offer->getId()->toRfc4122(), 'supplier' => $offer->getSupplier(), 'seller' => $offer->getSeller(),
                        'url' => $offer->getUrl(), 'currency' => $offer->getCurrency(), 'priceMinor' => $offer->getPriceMinor(),
                        'inboundShippingMinor' => $offer->getInboundShippingMinor(), 'fxRateCzk' => $offer->getFxRateCzk(),
                        'fxRateDate' => $offer->getFxRateDate()?->format('Y-m-d'),
                    ],
                    'unitCostCzkMinor' => $item->getUnitCostCzkMinor(),
                    'actualUnitCostCzkMinor' => $purchase['unitCostCzkMinor'] ?? null,
                    'purchase' => $purchase === null ? null : ['id' => $purchase['id'], 'reference' => $purchase['reference'], 'status' => $purchase['status']],
                    'promisedDate' => OrderQueues::promisedDate($order, $item)?->format('Y-m-d'),
                    'delayed' => OrderQueues::isDelayed($order, $item, $today),
                    'actions' => $this->rules->itemActions($s, $item),
                    'corrections' => $this->rules->itemCorrections($s, $item),
                ];
            }, $s->items()),
            'shipments' => array_map(function (Shipment $shipment) use ($s, $price): array {
                [$min, $max] = $s->leadTime($shipment);

                return [
                    'id' => $shipment->getId()->toRfc4122(), 'number' => $shipment->getPosition(), 'method' => $shipment->getMethod(),
                    'status' => $shipment->getStatus(), 'fee' => $price($shipment->getFeeMinor()),
                    'leadTimeMinDays' => $min, 'leadTimeMaxDays' => $max,
                    'scheduledFrom' => $shipment->getScheduledFrom()?->format(\DATE_ATOM), 'scheduledTo' => $shipment->getScheduledTo()?->format(\DATE_ATOM),
                    'handedOverAt' => $shipment->getHandedOverAt()?->format(\DATE_ATOM),
                    'ready' => $s->isReady($shipment),
                    'itemIds' => array_map(static fn (OrderItem $item): string => $item->getId()->toRfc4122(), $s->itemsOf($shipment)),
                    'actions' => $this->rules->shipmentActions($s, $shipment),
                    'corrections' => $this->rules->shipmentCorrections($s, $shipment),
                ];
            }, $s->shipments()),
            'payments' => array_map(fn (Payment $payment): array => $this->entry($s, $payment), $s->payments()),
            'events' => array_map(static fn (OrderEvent $event): array => [
                'id' => $event->getId()->toRfc4122(), 'type' => $event->getType(), 'data' => (object) $event->getData(),
                'actor' => $event->getActor(), 'createdAt' => $event->getCreatedAt()->format(\DATE_ATOM),
            ], $events),
            'subtotal' => $price($order->getSubtotalMinor()),
            'shipping' => $price($order->getShippingMinor()),
            'total' => $price($order->getTotalMinor()),
            'paid' => $price($s->netPaidMinor()),
            'amountDue' => $price($s->amountDueMinor()),
            'refundDue' => $price($s->refundDueMinor()),
            'economics' => $order->getCurrency() !== 'CZK' ? null : OrderEconomics::of(array_map(static fn (OrderItem $item): array => [
                'active' => $item->isActive(), 'quantity' => $item->getQuantity(), 'lineTotalMinor' => $item->getLineTotalMinor(),
                'snapshotUnitCostMinor' => $item->getUnitCostCzkMinor(),
                'actualUnitCostMinor' => $purchases[$item->getId()->toRfc4122()]['unitCostCzkMinor'] ?? null,
            ], $s->items()), $order->getShippingMinor()),
            'lookupToken' => $order->getLookupToken(),
            'actions' => $this->rules->orderActions($s),
            'claims' => array_map(
                static fn (ReturnClaim $claim): array => ClaimPresenter::present($claim, $today),
                $this->em->getRepository(ReturnClaim::class)->findBy(['order' => $order], ['openedAt' => 'DESC', 'id' => 'DESC']),
            ),
        ];
    }

    /**
     * The live (not cancelled) purchase line of each order line, one query per order.
     *
     * @return array<string, array{id: string, reference: string, status: string, unitCostCzkMinor: int}> by order item id
     */
    private function purchasesOf(string $orderId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT pl.order_item_id, p.id, p.reference, p.status, pl.unit_cost_czk_minor FROM purchase_line pl
            JOIN purchase p ON p.id = pl.purchase_id JOIN order_item i ON i.id = pl.order_item_id
            WHERE i.order_id = :order AND p.status <> :cancelled ORDER BY p.ordered_at, p.id',
            ['order' => strtolower($orderId), 'cancelled' => PurchaseStatus::CANCELLED],
        );
        $byItem = [];
        foreach ($rows as $row) {
            $byItem[$row['order_item_id']] = ['id' => $row['id'], 'reference' => $row['reference'], 'status' => $row['status'], 'unitCostCzkMinor' => (int) $row['unit_cost_czk_minor']];
        }

        return $byItem;
    }

    private function entry(OrderState $s, Payment $payment): array
    {
        return [
            'id' => $payment->getId()->toRfc4122(), 'kind' => $payment->getKind(), 'method' => $payment->getMethod(),
            'amount' => ['amount' => $payment->getAmountMinor(), 'currency' => $payment->getOrder()->getCurrency()],
            'shipmentId' => $payment->getShipment()?->getId()->toRfc4122(),
            'recordedAt' => $payment->getRecordedAt()->format(\DATE_ATOM), 'recordedBy' => $payment->getRecordedBy(),
            'note' => $payment->getNote(),
            'correctsId' => $payment->getCorrects()?->getId()->toRfc4122(),
            'voidedById' => $s->correctionOf($payment)?->getId()->toRfc4122(),
            'corrections' => $this->rules->paymentCorrections($s, $payment),
        ];
    }
}
