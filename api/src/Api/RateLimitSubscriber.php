<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * D09.3: throttles the public and sign-in endpoints per client IP, before any
 * sessioned admin work. The limits are what a lonely shop needs to survive
 * without a host; Redis and real numbers come with D09.1.
 */
final class RateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $apiPublicLimiter,
        private RateLimiterFactory $apiCheckoutLimiter,
        private RateLimiterFactory $apiAuthLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // run after the firewall's listeners could have resolved the session, before the controllers
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $this->decide($event->getRequest());
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    /** The 429 to answer, or null when the request may go on. Public for the tests. */
    public function decide(Request $request): ?JsonResponse
    {
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return null;
        }
        $limiter = match (true) {
            str_starts_with($path, '/api/checkout') => $this->apiCheckoutLimiter,
            $path === '/api/admin/login', str_starts_with($path, '/api/account/') => $this->apiAuthLimiter,
            // the admin works in a session, the login above is the only unauthenticated admin path
            str_starts_with($path, '/api/admin') => null,
            default => $this->apiPublicLimiter,
        };
        if ($limiter === null) {
            return null;
        }
        $rate = $limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if ($rate->isAccepted()) {
            return null;
        }

        return new JsonResponse(
            ['message' => 'Too many requests, slow down a little', 'code' => 'too_many_requests'],
            429,
            ['Retry-After' => (string) max(1, $rate->getRetryAfter()->getTimestamp() - time())],
        );
    }
}
