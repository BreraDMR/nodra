<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Tests\Support\ApiTestCase;

final class AdminAccessTest extends ApiTestCase
{
    private const CATEGORY = ['slug' => 't-new', 'nameCs' => 'Nové', 'nameDe' => 'Neu', 'nameEn' => 'New'];

    public function testCatalogAdminNeedsASession(): void
    {
        $this->getJson('/api/admin/categories');
        self::assertResponseStatusCodeSame(401);
        $this->sendJson('POST', '/api/admin/categories', self::CATEGORY);
        self::assertResponseStatusCodeSame(401);
        $this->getJson('/api/admin/products');
        self::assertResponseStatusCodeSame(401);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->loginAdmin();
        $this->client->request('POST', '/api/admin/logout');
        $this->sendJson('POST', '/api/admin/login', ['email' => 'test-admin@nodra.test', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);
        $this->getJson('/api/admin/categories');
        self::assertResponseStatusCodeSame(401);
    }

    public function testWritesNeedTheCsrfToken(): void
    {
        $token = $this->loginAdmin();

        $this->getJson('/api/admin/categories');
        self::assertResponseIsSuccessful();
        $this->sendJson('POST', '/api/admin/categories', self::CATEGORY);
        self::assertResponseStatusCodeSame(403);
        $this->sendJson('POST', '/api/admin/categories', self::CATEGORY, 'not-the-token');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->db()->fetchOne("SELECT id FROM category WHERE slug = 't-new'"));

        $this->sendJson('POST', '/api/admin/categories', self::CATEGORY, $token);
        self::assertResponseStatusCodeSame(201);
    }

    public function testMalformedIdsAreNotFound(): void
    {
        $token = $this->loginAdmin();
        $variant = ['sku' => 'T-X', 'labelCs' => 'X', 'labelDe' => 'X', 'labelEn' => 'X', 'priceCzk' => 100, 'priceEur' => 4];

        $this->getJson('/api/admin/orders/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('POST', '/api/admin/orders/not-a-uuid/confirm', ['customerAgreedVia' => ['channel' => 'phone', 'note' => 'x']], $token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('POST', '/api/admin/orders/01890000-0000-7000-8000-000000000000/items/not-a-uuid/received', [], $token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('PUT', '/api/admin/variants/not-a-uuid', $variant, $token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('POST', '/api/admin/products/not-a-uuid/variants', $variant, $token);
        self::assertResponseStatusCodeSame(404);
    }
}
