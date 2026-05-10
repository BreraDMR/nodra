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
    private const PRIVATE_KEY_PARTS = ['cost', 'supplier', 'seller', 'offer', 'url', 'fx', 'markup', 'margin', 'rrp', 'market', 'landed', 'rule'];
    private const PRIVATE_VALUES = ['Secret Seller', 'supplier.example', 'maker.example', 'bike24', 'Heureka'];

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

        $responses = [];
        foreach (['cs', 'de', 'en'] as $locale) {
            $responses['list '.$locale] = $this->getJson('/api/products', ['locale' => $locale, 'category' => 't-private']);
            $responses['search '.$locale] = $this->getJson('/api/products', ['locale' => $locale, 'q' => 't-private']);
            $responses['detail '.$locale] = $this->getJson('/api/products/t-private-chain', ['locale' => $locale]);
            $responses['categories '.$locale] = $this->getJson('/api/categories', ['locale' => $locale]);
            $responses['facets '.$locale] = $this->getJson('/api/categories/t-private/facets', ['locale' => $locale]);
        }
        $this->client->request('POST', '/api/checkout', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))], content: json_encode([
            'locale' => 'cs',
            'customer' => ['name' => 'Rider', 'email' => 'rider@example.test', 'country' => 'CZ', 'address' => 'Demo 1', 'postalCode' => '11000', 'district' => 'Praha 1'],
            'items' => [['variantId' => $variant->getId()->toRfc4122(), 'quantity' => 1]],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $responses['checkout'] = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $responses['order lookup'] = $this->getJson('/api/orders/'.$responses['checkout']['reference'], ['token' => $responses['checkout']['lookupToken']]);

        // the walk must see real content, not empty pages
        self::assertSame('orderable', $responses['detail cs']['variants'][0]['availability']['status']);
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
