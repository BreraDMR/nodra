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
        if ($request->promotionCode !== null) {
            throw new \InvalidArgumentException('Promotion codes are not available in this demo');
        }
        $requestHash = hash('sha256', json_encode([$request->locale, $customer, $quantities], JSON_THROW_ON_ERROR));
        $currency = $request->locale === 'cs' ? 'CZK' : 'EUR';

        try {
            $order = $this->db->transactional(function () use ($request, $key, $requestHash, $customer, $quantities, $currency): ShopOrder {
                $this->db->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
                $existing = $this->em->getRepository(ShopOrder::class)->findOneBy(['idempotencyKey' => $key]);
                if ($existing !== null) {
                    if (!hash_equals($existing->getRequestHash(), $requestHash)) {
                        throw new \DomainException('Idempotency key was used with different order details');
                    }
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

                $shipping = $currency === 'CZK' ? 8900 : 390;
                $order = new ShopOrder($key, $requestHash, $request->locale, $currency, $customer['name'], $customer['email'], $customer['country'], $customer['address'], $customer['postalCode'], $customer['district'], $subtotal, $shipping);
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
            if (!hash_equals($order->getRequestHash(), $requestHash)) {
                throw new \DomainException('Idempotency key was used with different order details');
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

    private function customer(array $input): array
    {
        $fields = ['name', 'email', 'country', 'address', 'postalCode', 'district'];
        foreach ($fields as $field) {
            if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
                throw new \InvalidArgumentException('Customer '.$field.' is required');
            }
        }
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required');
        }
        $country = strtoupper(trim($input['country']));
        if ($country !== 'CZ') {
            throw new \InvalidArgumentException('Delivery is available only within Czechia');
        }
        $postalCode = trim($input['postalCode']);
        if (!preg_match('/^\d{3}\s?\d{2}$/', $postalCode)) {
            throw new \InvalidArgumentException('A Czech postal code is required');
        }

        return [
            'name' => mb_substr(trim($input['name']), 0, 160),
            'email' => mb_substr(trim($input['email']), 0, 180),
            'country' => $country,
            'address' => mb_substr(trim($input['address']), 0, 255),
            'postalCode' => $postalCode,
            'district' => mb_substr(trim($input['district']), 0, 120),
        ];
    }

    private function quantities(array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['variantId'], $item['quantity']) || !Uuid::isValid($item['variantId']) || !is_int($item['quantity']) || $item['quantity'] < 1 || $item['quantity'] > 10) {
                throw new \InvalidArgumentException('Each item needs a valid variant and quantity from 1 to 10');
            }
            $quantities[$item['variantId']] = ($quantities[$item['variantId']] ?? 0) + $item['quantity'];
            if ($quantities[$item['variantId']] > 10) {
                throw new \InvalidArgumentException('Only 10 units of one variant are allowed per order');
            }
        }
        ksort($quantities);

        return $quantities;
    }
}
