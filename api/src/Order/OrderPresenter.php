<?php

declare(strict_types=1);

namespace App\Order;

use App\Delivery\DeliveryMethod;
use App\Delivery\DeliverySettings;
use App\Entity\OrderEvent;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Shipment;
use Doctrine\ORM\EntityManagerInterface;

/**
 * JSON shapes of an order. The public receipt never carries cost, supplier, offer, procurement or journal data;
 * the admin view has everything.
 */
final class OrderPresenter
{
    public function __construct(private EntityManagerInterface $em, private OrderLoader $loader, private OrderRules $rules, private DeliverySettings $delivery) {}

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
            'pickupNote' => $hasPickup ? $this->delivery->pickupNote : null,
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

        return [
            'id' => $order->getId()->toRfc4122(),
            'reference' => $order->getReference(),
            'status' => $order->getStatus(),
            'paymentStatus' => $order->getPaymentStatus(),
            'locale' => $order->getLocale(),
            'currency' => $order->getCurrency(),
            'fulfilment' => $order->getFulfilment(),
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
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
            'items' => array_map(fn (OrderItem $item): array => [
                'id' => $item->getId()->toRfc4122(), 'variantId' => $item->getVariant()?->getId()->toRfc4122(),
                'name' => $item->getProductName(), 'variant' => $item->getVariantLabel(), 'sku' => $item->getSku(),
                'quantity' => $item->getQuantity(), 'unitPrice' => $item->getUnitPriceMinor(), 'lineTotal' => $item->getLineTotalMinor(),
                'state' => $item->getState(), 'procurementStatus' => $item->getProcurementStatus(),
                'supplierReference' => $item->getSupplierReference(), 'shipmentId' => $item->getShipment()->getId()->toRfc4122(),
                'replacesItemId' => $item->getReplacesItem()?->getId()->toRfc4122(),
                'availabilityStatus' => $item->getAvailabilityStatus(),
                'leadTimeMinDays' => $item->getLeadTimeMinDays(), 'leadTimeMaxDays' => $item->getLeadTimeMaxDays(),
                'supplierOfferId' => $item->getSupplierOffer()?->getId()->toRfc4122(),
                'unitCostCzkMinor' => $item->getUnitCostCzkMinor(),
                'actions' => $this->rules->itemActions($s, $item),
            ], $s->items()),
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
                ];
            }, $s->shipments()),
            'payments' => array_map($this->payment(...), $s->payments()),
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
            'lookupToken' => $order->getLookupToken(),
            'actions' => $this->rules->orderActions($s),
        ];
    }

    public function payment(Payment $payment): array
    {
        return [
            'id' => $payment->getId()->toRfc4122(), 'kind' => $payment->getKind(), 'method' => $payment->getMethod(),
            'amount' => ['amount' => $payment->getAmountMinor(), 'currency' => $payment->getOrder()->getCurrency()],
            'shipmentId' => $payment->getShipment()?->getId()->toRfc4122(),
            'recordedAt' => $payment->getRecordedAt()->format(\DATE_ATOM), 'recordedBy' => $payment->getRecordedBy(),
            'note' => $payment->getNote(),
        ];
    }
}
