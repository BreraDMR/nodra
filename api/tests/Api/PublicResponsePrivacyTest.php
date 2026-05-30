<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Walks every public response and fails on any key that looks like cost, sourcing or reference price data.
 * Public JSON may say how available a variant is and how long it takes, nothing about where it comes from.
 */
final class PublicResponsePrivacyTest extends ApiTestCase
{
    private const PRIVATE_KEY_PARTS = ['cost', 'supplier', 'seller', 'offer', 'url', 'fx', 'markup', 'margin', 'rrp', 'market', 'landed', 'rule', 'procurement', 'actor', 'event', 'recorded'];
    private const PRIVATE_VALUES = ['Secret Seller', 'supplier.example', 'maker.example', 'bike24', 'Heureka', 'SUP-REF-4711', 'test-admin@nodra.test'];

    public function testPublicResponsesCarryNoCostSupplierOrReferencePrice(): void
    {
        $b = $this->builder();
        $category = $b->category('t-private');
        $b->rule($category, 0, null, 3000);
        $product = $b->product('t-private-chain', $category, 'KMC', copy: ['cs' => ['inBox' => 'Řetěz']]);
        $variant = $b->variant($product, 'T-PRIVATE-1');
        $variant->setReferencePrices(2800, 'EUR', 'https://maker.example/rrp', new \DateTimeImmutable('2026-09-20'), 60000, 'Heureka', new \DateTimeImmutable('2026-09-27'));
        $b->pricedOffer($product, $variant, 2000, 'EUR', 25_000_000, 200);
        $b->pricedOffer($product, null, 49900);
        // goods NODRA holds, with an offer still attached for the cost snapshot
        $held = $b->variant($product, 'T-PRIVATE-2', stock: 2);
        $b->pricedOffer($product, $held, 1500, 'EUR', 25_000_000);
        $items = [$variant->getId()->toRfc4122() => 1, $held->getId()->toRfc4122() => 1];

        $responses = [];
        foreach (['cs', 'de', 'en'] as $locale) {
            $responses['list '.$locale] = $this->getJson('/api/products', ['locale' => $locale, 'category' => 't-private']);
            $responses['search '.$locale] = $this->getJson('/api/products', ['locale' => $locale, 'q' => 't-private']);
            $responses['detail '.$locale] = $this->getJson('/api/products/t-private-chain', ['locale' => $locale]);
            $responses['categories '.$locale] = $this->getJson('/api/categories', ['locale' => $locale]);
            $responses['facets '.$locale] = $this->getJson('/api/categories/t-private/facets', ['locale' => $locale]);
        }
        foreach (['prague_personal', 'pickup_andel'] as $method) {
            $responses['quote '.$method] = $this->quote($items, $method);
        }
        $responses['checkout'] = $this->checkout($items, fulfilment: 'split');
        self::assertResponseStatusCodeSame(201);
        $lookup = ['token' => $responses['checkout']['lookupToken']];
        $responses['order lookup'] = $this->getJson('/api/orders/'.$responses['checkout']['reference'], $lookup);

        // after the admin bought the line, recorded money and wrote the journal, the receipt still says nothing of it
        $token = $this->loginAdmin();
        $order = $this->getJson('/api/admin/orders/'.$this->orderId($responses['checkout']['reference']));
        $toOrder = array_values(array_filter($order['items'], static fn (array $item): bool => $item['procurementStatus'] === 'to_order'))[0];
        $this->sendJson('POST', '/api/admin/orders/'.$order['id'].'/confirm', ['customerAgreedVia' => ['channel' => 'phone', 'note' => 'Agreed']], $token);
        $this->sendJson('POST', '/api/admin/orders/'.$order['id'].'/items/'.$toOrder['id'].'/ordered', ['supplierReference' => 'SUP-REF-4711'], $token);
        self::assertResponseIsSuccessful();
        $this->sendJson('POST', '/api/admin/orders/'.$order['id'].'/payments', ['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 1000, 'note' => 'Secret Seller'], $token, ['HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame(201);
        $responses['order lookup after admin'] = $this->getJson('/api/orders/'.$responses['checkout']['reference'], $lookup);

        // the walk must see real content, not empty pages
        self::assertSame('orderable', $responses['detail cs']['variants'][0]['availability']['status']);
        self::assertCount(2, $responses['quote prague_personal']['options']['split']['shipments']);
        self::assertSame(['confirmed', 1000], [$responses['order lookup after admin']['status'], $responses['order lookup after admin']['paid']['amount']]);
        self::assertSame('t-private-chain', $responses['list en']['items'][0]['slug']);
        foreach ($responses as $name => $payload) {
            foreach ($this->keys($payload) as $path) {
                foreach (self::PRIVATE_KEY_PARTS as $part) {
                    self::assertStringNotContainsString($part, strtolower($path), $name.': '.$path);
                }
            }
            $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            foreach (self::PRIVATE_VALUES as $value) {
                self::assertStringNotContainsString($value, $raw, $name);
            }
        }
    }

    /** @return list<string> every key as a dotted path */
    private function keys(array $payload, string $prefix = ''): array
    {
        $paths = [];
        foreach ($payload as $key => $value) {
            $path = is_int($key) ? $prefix : ltrim($prefix.'.'.$key, '.');
            if (!is_int($key)) {
                $paths[] = $path;
            }
            if (is_array($value)) {
                array_push($paths, ...$this->keys($value, $path));
            }
        }

        return $paths;
    }
}
