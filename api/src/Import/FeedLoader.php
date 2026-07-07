<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Reads a supplier's stored feed: an http(s) URL or a local path. Bounded by a timeout and a size
 * cap; a download that fails or overflows throws — the refresh then journals a failed run and the
 * catalogue stays exactly as it was (D03.4). Tests inject a resolver instead of reaching the net.
 */
final class FeedLoader
{
    private ?\Closure $httpResolver;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'app.import.download_timeout')] private int $timeoutSeconds,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'app.import.download_max_bytes')] private int $maxBytes,
        ?\Closure $httpResolver = null,
    ) {
        $this->httpResolver = $httpResolver;
    }

    /** Replaces the HTTP transport, so tests never touch the internet. */
    public function setHttpResolver(?\Closure $httpResolver): void
    {
        $this->httpResolver = $httpResolver;
    }

    public function load(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            if ($this->httpResolver !== null) {
                $bytes = ($this->httpResolver)($url);
            } else {
                $context = stream_context_create(['http' => ['timeout' => $this->timeoutSeconds, 'user_agent' => 'NODRA feed import']]);
                $bytes = @file_get_contents($url, false, $context);
                if ($bytes === false) {
                    throw new \RuntimeException(sprintf('The feed could not be downloaded from %s', $url));
                }
            }
        } else {
            $path = preg_replace('#^file://#i', '', $url) ?? $url;
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('The feed file %s does not exist', $path));
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException(sprintf('The feed file %s could not be read', $path));
            }
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new \RuntimeException(sprintf('The feed is larger than the %d MB cap', (int) ($this->maxBytes / 1_000_000)));
        }

        return $bytes;
    }
}
