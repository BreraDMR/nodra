<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\RateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * D09.3: the throttling decides per path family and answers 429 in the API's
 * Problem shape. Built on hand-made limiters because the functional suite runs
 * with the limits far away — the whole suite shares one client IP.
 */
final class RateLimitTest extends TestCase
{
    private const LIMIT = 2;

    public function testPublicBucketAnswers429WhenExhausted(): void
    {
        $subscriber = $this->subscriber();
        for ($i = 0; $i < self::LIMIT; $i++) {
            self::assertNull($subscriber->decide(Request::create('/api/products')));
        }
        $response = $subscriber->decide(Request::create('/api/products'));
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('too_many_requests', json_decode($response->getContent(), true)['code']);
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
    }

    public function testCheckoutAndAuthCountApartFromTheCatalogue(): void
    {
        $subscriber = $this->subscriber();
        // exhaust the public bucket
        for ($i = 0; $i < self::LIMIT; $i++) {
            $subscriber->decide(Request::create('/api/products'));
        }
        self::assertNull($subscriber->decide(Request::create('/api/checkout/quote')), 'checkout has its own bucket');
        self::assertNull($subscriber->decide(Request::create('/api/account/demo-login')), 'auth has its own bucket');
        self::assertNotNull($subscriber->decide(Request::create('/api/products')), 'the public bucket is still full');
    }

    public function testSignInEndpointsAreThrottledAndTheSessionedAdminIsNot(): void
    {
        $subscriber = $this->subscriber();
        for ($i = 0; $i < self::LIMIT; $i++) {
            $subscriber->decide(Request::create('/api/admin/login', 'POST'));
        }
        self::assertNotNull($subscriber->decide(Request::create('/api/admin/login', 'POST')));
        // the admin at work can never trip the limiter, whatever the IP has done
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($subscriber->decide(Request::create('/api/admin/orders')));
        }
        self::assertNull($subscriber->decide(Request::create('/api/products')), 'unrelated path outside /api/ is never limited');
    }

    private function subscriber(): RateLimitSubscriber
    {
        $factory = static fn (): RateLimiterFactory => new RateLimiterFactory(
            ['id' => bin2hex(random_bytes(4)), 'policy' => 'sliding_window', 'limit' => self::LIMIT, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        return new RateLimitSubscriber($factory(), $factory(), $factory());
    }
}
