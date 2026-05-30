<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Requests here send no Accept header on purpose: a browser or curl must get the same JSON errors.
 */
final class RequestValidationTest extends ApiTestCase
{
    public function testBadCatalogueQueryIsA422WithViolations(): void
    {
        $cases = [
            'page=abc' => 'page',
            'page=0' => 'page',
            'sort=cheapest' => 'sort',
            'locale=pl' => 'locale',
            'q='.str_repeat('a', 81) => 'q',
            'brand='.str_repeat('b', 81) => 'brand',
            'attr[speeds]='.str_repeat('1', 201) => 'attr[speeds]',
            'attr=11' => 'attr',
        ];
        foreach ($cases as $query => $field) {
            $error = $this->problem('GET', '/api/products?'.$query, 422);
            self::assertContains($field, array_column($error['violations'], 'field'), $query);
        }
        // the other catalogue endpoints read the same query object
        foreach (['/api/categories', '/api/categories/lights/facets', '/api/products/some-product'] as $uri) {
            $error = $this->problem('GET', $uri.'?locale=pl', 422);
            self::assertSame('locale', $error['violations'][0]['field'], $uri);
        }
    }

    public function testBadAdminPayloadIsA422WithViolations(): void
    {
        $token = $this->loginAdmin();
        $product = [
            'slug' => 't-invalid', 'category' => 'lights', 'status' => 'published',
            'nameCs' => '', 'nameDe' => 'Licht', 'nameEn' => 'Light',
            'shortCs' => 'Krátký', 'shortDe' => 'Kurz', 'shortEn' => 'Short',
            'image' => '/images/light.png', 'priceCzk' => -1, 'priceEur' => 100,
        ];

        $error = $this->problem('POST', '/api/admin/products', 422, $product, $token);
        self::assertEqualsCanonicalizing(['nameCs', 'priceCzk'], array_column($error['violations'], 'field'));
        self::assertStringContainsString('nameCs', $error['message']);

        // a value of the wrong type never reaches the validator, it's reported the same way
        $error = $this->problem('POST', '/api/admin/products', 422, ['priceCzk' => 'cheap'] + $product, $token);
        self::assertContains('priceCzk', array_column($error['violations'], 'field'));

        $error = $this->problem('POST', '/api/admin/orders/01890000-0000-7000-8000-000000000000/confirm', 422, ['customerAgreedVia' => ['channel' => 'pigeon', 'note' => '']], $token);
        self::assertEqualsCanonicalizing(['customerAgreedVia[channel]', 'customerAgreedVia[note]'], array_column($error['violations'], 'field'));
        $error = $this->problem('POST', '/api/admin/orders/01890000-0000-7000-8000-000000000000/payments', 422, ['kind' => 'gift', 'method' => 'cash', 'amountMinor' => 0], $token);
        self::assertEqualsCanonicalizing(['kind', 'amountMinor'], array_column($error['violations'], 'field'));
        $error = $this->problem('POST', '/api/admin/orders/01890000-0000-7000-8000-000000000000/shipments/01890000-0000-7000-8000-000000000000/schedule', 422, ['from' => 'not a date', 'to' => '2026-10-01T19:00:00+02:00'], $token);
        self::assertSame('from', $error['violations'][0]['field']);
        self::assertFalse($this->db()->fetchOne("SELECT id FROM product WHERE slug = 't-invalid'"));
    }

    public function testBadAdminQueryIsA422(): void
    {
        $this->loginAdmin();

        self::assertSame('page', $this->problem('GET', '/api/admin/products?page=0', 422)['violations'][0]['field']);
        self::assertSame('q', $this->problem('GET', '/api/admin/products?q='.str_repeat('x', 81), 422)['violations'][0]['field']);
        self::assertSame('page', $this->problem('GET', '/api/admin/orders?page=last', 422)['violations'][0]['field']);
        self::assertSame('paymentStatus', $this->problem('GET', '/api/admin/orders?paymentStatus=maybe', 422)['violations'][0]['field']);
    }

    public function testOtherRequestErrorsAreJsonToo(): void
    {
        $error = $this->problem('GET', '/api/admin/products', 401);
        self::assertArrayNotHasKey('violations', $error);

        $this->client->request('POST', '/api/checkout', server: ['CONTENT_TYPE' => 'application/json'], content: '{"locale": ');
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertNotSame('', json_decode((string) $this->client->getResponse()->getContent(), true)['message']);
    }

    /** Sends the request without Accept and checks the error is a JSON problem with a message. */
    private function problem(string $method, string $uri, int $status, ?array $payload = null, ?string $csrf = null): array
    {
        $server = $payload === null ? [] : ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $this->client->request($method, $uri, server: $server, content: $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame($status, $method.' '.$uri);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $error = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($error['message']);
        self::assertNotSame('', $error['message']);

        return $error;
    }
}
