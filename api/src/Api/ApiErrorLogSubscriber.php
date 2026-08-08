<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * D09.3: one JSON line per API error, appended to var/log/api-errors.jsonl.
 * A shop without a host has no metrics dashboard; this file is the place to
 * look when something misbehaves — 404 probes, failed checkouts, 500s.
 */
final class ApiErrorLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private string $logFile,
        private TokenStorageInterface $tokens,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // late, so the answer is final whatever listener rendered it
        return [KernelEvents::RESPONSE => ['onKernelResponse', -256]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/') || $response->getStatusCode() < 400) {
            return;
        }
        $body = $this->body($response);
        $entry = [
            'time' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'method' => $request->getMethod(),
            'path' => $path,
            'status' => $response->getStatusCode(),
            'code' => is_array($body) ? ($body['code'] ?? null) : null,
            'message' => is_array($body) ? ($body['message'] ?? $body['detail'] ?? null) : null,
            'ip' => $request->getClientIp(),
            'actor' => $this->actor($request),
        ];
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->logFile, json_encode($entry, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    /** @return array<string, mixed>|null */
    private function body(Response $response): ?array
    {
        try {
            $decoded = json_decode($response->getContent() ?: '', true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            // streamed or already-sent bodies are of no use here anyway
            return null;
        }
    }

    /** Who caused it, when it can be told: the admin email or a signed-in customer. */
    private function actor(Request $request): ?string
    {
        $user = ($this->tokens->getToken() ?? $request->attributes->get('_security_current_security_token'))?->getUser();

        return is_object($user) && method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : null;
    }
}
