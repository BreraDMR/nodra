<?php

declare(strict_types=1);

// Measures sequential p50/p95 latency of the public API against the perf stack
// on 127.0.0.1:8001 (a throwaway copy of the database, see docs/perf-local.md).
// Request counts stay under the D09.3 rate limits (240/min catalog, 30/min checkout);
// a 429 means the budget was exceeded and shows up as a failure.
//
// Usage: php scripts/perf-run.php [requests_per_get_scenario]

$base = getenv('PERF_BASE') ?: 'http://127.0.0.1:8001';
$perScenario = max(5, (int) ($argv[1] ?? 20));

/** @return array{status: int, body: string} */
function request(string $method, string $url, array $headers = [], ?string $content = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $content,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'body' => (string) $body];
}

/** Measures one request in milliseconds, fails loudly on any non-2xx. */
function timed(callable $request): float
{
    $start = hrtime(true);
    $response = $request();
    $ms = (hrtime(true) - $start) / 1e6;
    if ($response['status'] < 200 || $response['status'] >= 300) {
        fwrite(STDERR, "FAIL HTTP {$response['status']}: ".substr($response['body'], 0, 200)."\n");
        exit(1);
    }

    return $ms;
}

/** @param list<float> $samples */
function percentiles(array $samples): array
{
    sort($samples);
    $pick = static function (float $p) use ($samples): float {
        $index = (int) floor($p * (count($samples) - 1));

        return round($samples[$index], 1);
    };

    return [
        'n' => count($samples),
        'p50' => $pick(0.5),
        'p95' => $pick(0.95),
        'max' => round(max($samples), 1),
        'mean' => round(array_sum($samples) / count($samples), 1),
    ];
}

// --- pick a synthetic variant to buy: own stock 0, so the order is sourced from its offer
$product = request('GET', "$base/api/products/perf-2-p?locale=cs");
$product = json_decode($product['body'], true, flags: JSON_THROW_ON_ERROR);
$variantId = $product['variants'][0]['id'];

$quoteBody = json_encode([
    'locale' => 'cs',
    'items' => [['variantId' => $variantId, 'quantity' => 1]],
    'delivery' => ['method' => 'prague_personal', 'postalCode' => '120 00'],
], JSON_THROW_ON_ERROR);
$quote = request('POST', "$base/api/checkout/quote", ['Content-Type: application/json'], $quoteBody);
$quote = json_decode($quote['body'], true, flags: JSON_THROW_ON_ERROR);
$expectedTotal = $quote['options']['together']['total']['amount'];

$checkoutBody = json_encode([
    'locale' => 'cs',
    'customer' => [
        'name' => 'Perf Runner', 'email' => 'perf@example.test', 'phone' => '777 000 111', 'contactChannel' => 'whatsapp',
        'address' => 'Perfúzní 1', 'city' => 'Praha', 'postalCode' => '120 00', 'district' => 'Praha 2',
    ],
    'items' => [['variantId' => $variantId, 'quantity' => 1]],
    'delivery' => ['method' => 'prague_personal', 'fulfilment' => 'together'],
    'consents' => ['privacy' => true],
    'expectedTotal' => $expectedTotal,
], JSON_THROW_ON_ERROR);

$scenarios = [
    'categories_tree' => fn (): array => request('GET', "$base/api/categories?locale=cs"),
    'browse_featured_p1' => fn (): array => request('GET', "$base/api/products?locale=cs"),
    'browse_price_asc_p1' => fn (): array => request('GET', "$base/api/products?locale=cs&sort=price_asc"),
    'browse_category_subtree' => fn (): array => request('GET', "$base/api/products?locale=cs&category=components&sort=price_asc"),
    'browse_subtree_last_page' => fn (): array => request('GET', "$base/api/products?locale=cs&category=components&sort=price_asc&page=89"),
    'search_by_name' => fn (): array => request('GET', "$base/api/products?locale=cs&q=".urlencode('Perf 50')),
    'search_by_mpn' => fn (): array => request('GET', "$base/api/products?locale=cs&q=".urlencode('PFM-000500')),
    'facets_components' => fn (): array => request('GET', "$base/api/categories/components/facets?locale=cs"),
    'product_card' => fn (): array => request('GET', "$base/api/products/perf-500-p?locale=cs"),
];

$results = [];
foreach ($scenarios as $name => $call) {
    timed($call); // warm-up, excluded
    $samples = [];
    for ($i = 0; $i < $perScenario; ++$i) {
        $samples[] = timed($call);
    }
    $results[$name] = percentiles($samples);
    echo "$name: p50 {$results[$name]['p50']} ms, p95 {$results[$name]['p95']} ms\n";
}

// writes: quote shares the checkout rate limit (30/min), 8+8 stay under it
foreach (['checkout_quote' => static function () use ($base, $quoteBody): array {
    return request('POST', "$base/api/checkout/quote", ['Content-Type: application/json'], $quoteBody);
}, 'checkout_place' => static function () use ($base, $checkoutBody): array {
    return request('POST', "$base/api/checkout", [
        'Content-Type: application/json', 'Idempotency-Key: '.bin2hex(random_bytes(12)),
    ], $checkoutBody);
}] as $name => $call) {
    timed($call);
    $samples = [];
    for ($i = 0; $i < 8; ++$i) {
        $samples[] = timed($call);
    }
    $results[$name] = percentiles($samples);
    echo "$name: p50 {$results[$name]['p50']} ms, p95 {$results[$name]['p95']} ms\n";
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
