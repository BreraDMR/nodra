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
}
