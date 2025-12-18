<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Entity\OrderItem;
use App\Entity\ProductVariant;
use App\Entity\ShopOrder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CheckoutService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db) {}

    public function place(CheckoutRequest $request, string $key): array
    {
        if (strlen($key) < 16 || strlen($key) > 80) {
            throw new \InvalidArgumentException('Idempotency-Key must contain 16 to 80 characters');
        }
        $customer = $this->customer($request->customer);
        $quantities = $this->quantities($request->items);
        $currency = $request->locale === 'cs' ? 'CZK' : 'EUR';

        try {
            $order = $this->db->transactional(function () use ($request, $key, $customer, $quantities, $currency): ShopOrder {
                $existing = $this->em->getRepository(ShopOrder::class)->findOneBy(['idempotencyKey' => $key]);
                if ($existing !== null) {
                    return $existing;
                }

                $lines = [];
                $subtotal = 0;
                foreach ($quantities as $id => $quantity) {
                    $row = $this->db->fetchAssociative('SELECT v.id, v.stock, v.active, v.price_czk, v.price_eur, v.label,
                        p.status, p.copy FROM product_variant v JOIN product p ON p.id = v.product_id
                        WHERE v.id = :id FOR UPDATE OF v', ['id' => $id]);
                    if ($row === false || !$row['active'] || $row['status'] !== 'published') {
                        throw new \DomainException('A selected product is no longer available');
                    }
                    if ((int) $row['stock'] < $quantity) {
                        throw new \DomainException('There is not enough stock for one of the selected products');
                    }
                    $price = (int) $row[$currency === 'CZK' ? 'price_czk' : 'price_eur'];
                    $subtotal += $price * $quantity;
                    $copy = json_decode($row['copy'], true, flags: JSON_THROW_ON_ERROR)[$request->locale];
                    $label = json_decode($row['label'], true, flags: JSON_THROW_ON_ERROR)[$request->locale];
                    $lines[] = [$id, $quantity, $price, $copy['name'], $label];
                }

                $shipping = match ($customer['country']) {
                    'CZ' => $currency === 'CZK' ? 8900 : 390,
                    'DE' => $currency === 'CZK' ? 16900 : 690,
                    default => $currency === 'CZK' ? 29900 : 1200,
                };
                $order = new ShopOrder($key, $request->locale, $currency, $customer['name'], $customer['email'], $customer['country'], $customer['address'], $customer['postalCode'], $subtotal, $shipping);
                $this->em->persist($order);

                foreach ($lines as [$id, $quantity, $price, $name, $label]) {
                    $variant = $this->em->find(ProductVariant::class, Uuid::fromString($id));
                    $variant->adjustStock(-$quantity);
                    $this->em->persist(new OrderItem($order, $variant, $name, $label, $quantity, $price));
                }
                $this->em->flush();

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            $this->em->clear();
            $order = $this->em->getRepository(ShopOrder::class)->findOneBy(['idempotencyKey' => $key]);
            if ($order === null) {
                throw new \DomainException('The order could not be completed');
            }
        }

        return $this->receipt($order);
    }

    public function lookup(string $reference, string $token): ?array
    {
        $order = $this->em->getRepository(ShopOrder::class)->findOneBy(['reference' => $reference]);
        if ($order === null || !hash_equals($order->getLookupToken(), $token)) {
            return null;
        }

        return $this->receipt($order);
    }

    public function receipt(ShopOrder $order): array
    {
        $items = $this->em->getRepository(OrderItem::class)->findBy(['order' => $order]);
        $price = fn (int $amount): array => ['amount' => $amount, 'currency' => $order->getCurrency()];

        return [
            'reference' => $order->getReference(),
            'status' => $order->getStatus(),
            'items' => array_map(static fn (OrderItem $item): array => [
                'name' => $item->getProductName(), 'variant' => $item->getVariantLabel(),
                'sku' => $item->getSku(), 'quantity' => $item->getQuantity(),
                'unitPrice' => $item->getUnitPriceMinor(), 'lineTotal' => $item->getLineTotalMinor(),
            ], $items),
            'subtotal' => $price($order->getSubtotalMinor()),
            'shipping' => $price($order->getShippingMinor()),
            'total' => $price($order->getTotalMinor()),
            'lookupToken' => $order->getLookupToken(),
        ];
    }
}
