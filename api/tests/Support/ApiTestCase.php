<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AdminUser;
use App\Entity\CustomerAccount;
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
    /** A Prague customer; the phone is stored as +420777123456. */
    protected const CUSTOMER = [
        'name' => 'Rider', 'email' => 'rider@example.test', 'phone' => '777 123 456', 'contactChannel' => 'whatsapp',
        'address' => 'Vinohradská 1', 'city' => 'Praha', 'postalCode' => '120 00', 'district' => 'Praha 2',
    ];

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

    /** @param array<string, string> $server extra headers in $_SERVER form, e.g. HTTP_IDEMPOTENCY_KEY */
    protected function sendJson(string $method, string $uri, array $payload, ?string $csrf = null, array $server = []): array
    {
        $server += ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
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

    /** @param array<string, int> $items variant id => quantity */
    protected function quote(array $items, string $method = 'prague_personal', ?string $postalCode = '120 00', string $locale = 'cs'): array
    {
        return $this->sendJson('POST', '/api/checkout/quote', ['locale' => $locale, 'items' => self::basket($items), 'delivery' => ['method' => $method, 'postalCode' => $postalCode]]);
    }

    /**
     * Sends an order the way the storefront does: quote first, then checkout with the quoted total of the chosen option.
     *
     * @param array<string, int> $items variant id => quantity
     */
    protected function checkout(array $items, string $method = 'prague_personal', string $fulfilment = 'together', array $customer = [], ?int $expectedTotal = null, ?string $key = null, string $locale = 'cs', array $consents = ['privacy' => true]): array
    {
        $customer += self::CUSTOMER;
        if ($expectedTotal === null) {
            $quote = $this->quote($items, $method, $customer['postalCode'] ?? null, $locale);
            $expectedTotal = $quote['options'][$fulfilment]['total']['amount'] ?? 0;
        }
        $this->client->request('POST', '/api/checkout', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key ?? bin2hex(random_bytes(12)),
        ], content: json_encode([
            'locale' => $locale, 'customer' => $customer, 'items' => self::basket($items),
            'delivery' => ['method' => $method, 'fulfilment' => $fulfilment], 'consents' => $consents, 'expectedTotal' => $expectedTotal,
        ], JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    protected function orderId(string $reference): string
    {
        return (string) $this->db()->fetchOne('SELECT id FROM shop_order WHERE reference = :reference', ['reference' => $reference]);
    }

    /** Signs a storefront customer in the way the Google callback does, through the session. */
    protected function signInCustomer(CustomerAccount $account): void
    {
        $session = $this->client->getSession();
        $session->set('customer_account_id', $account->getId()->toRfc4122());
        $session->save();
    }

    /** @param array<string, int> $items */
    protected static function basket(array $items): array
    {
        return array_map(static fn (string $id, int $quantity): array => ['variantId' => $id, 'quantity' => $quantity], array_keys($items), array_values($items));
    }

    protected function decode(): array
    {
        $response = $this->client->getResponse();
        // error pages may come back as HTML, tests on those only look at the status
        if (!str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [];
        }

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
