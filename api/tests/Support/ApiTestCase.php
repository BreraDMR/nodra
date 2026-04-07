<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * HTTP tests against the test database. DAMA wraps every test in a transaction
 * and rolls it back, so rows created here never outlive the test.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function builder(): CatalogBuilder
    {
        return new CatalogBuilder(static::getContainer()->get(EntityManagerInterface::class));
    }

    protected function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    protected function getJson(string $uri, array $query = []): array
    {
        $this->client->request('GET', $uri, $query, server: ['HTTP_ACCEPT' => 'application/json']);

        return $this->decode();
    }

    protected function sendJson(string $method, string $uri, array $payload, ?string $csrf = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($csrf !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $this->client->request($method, $uri, server: $server, content: json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /** Signs in through json_login like the admin app does and returns the CSRF token for writes. */
    protected function loginAdmin(): string
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new AdminUser('test-admin@nodra.test', '', 'Test Admin');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new AdminUser('test-admin@nodra.test', $hasher->hashPassword($admin, 'test-password'), 'Test Admin'));
        $em->flush();

        $this->sendJson('POST', '/api/admin/login', ['email' => 'test-admin@nodra.test', 'password' => 'test-password']);
        self::assertResponseIsSuccessful();
        $me = $this->getJson('/api/admin/me');
        self::assertResponseIsSuccessful();

        return $me['csrfToken'];
    }

    private function decode(): array
    {
        $response = $this->client->getResponse();
        // error pages may come back as HTML, tests on those only look at the status
        if (!str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [];
        }

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
