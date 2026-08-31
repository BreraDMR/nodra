<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Tests\Support\ApiTestCase;

/**
 * The web account page advertises the demo rider sign-in; the endpoint must stay
 * closed unless something explicitly turns it on (P02 portfolio demo, APP_DEMO_LOGIN),
 * because a real shop has no such account.
 */
final class DemoLoginTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        // back to the .env default, so later kernels boot closed again
        putenv('APP_DEMO_LOGIN=0');
        $_ENV['APP_DEMO_LOGIN'] = '0';
        $_SERVER['APP_DEMO_LOGIN'] = '0';
        parent::tearDown();
    }

    public function testDemoSignInStaysClosedWithoutTheFlag(): void
    {
        $this->flag('0');
        $this->client->request('POST', '/api/account/demo-login?locale=cs');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            ['message' => 'Demo sign-in is unavailable'],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    public function testTheFlagOpensTheDemoRiderAccount(): void
    {
        $this->flag('1');
        $this->client->request('POST', '/api/account/demo-login?locale=cs');

        self::assertResponseStatusCodeSame(200);
        $summary = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('NODRA Demo Rider', $summary['name']);
        self::assertSame(0, $summary['points']);
    }

    /** dotenv already filled $_ENV/$_SERVER from .env, so every source must switch together. */
    private function flag(string $value): void
    {
        putenv('APP_DEMO_LOGIN=' . $value);
        $_ENV['APP_DEMO_LOGIN'] = $value;
        $_SERVER['APP_DEMO_LOGIN'] = $value;
    }
}
