<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps config/api_doc/shop.yaml in line with what the controllers really send.
 */
final class ShopContractTest extends ApiTestCase
{
    public function testEveryAdminOperationAnswers401WithoutASessionAndSaysSo(): void
    {
        $checked = 0;
        foreach ($this->contract()['paths'] as $path => $operations) {
            // login is public and logout is handled by the firewall
            if (!str_starts_with($path, '/api/admin/') || in_array($path, ['/api/admin/login', '/api/admin/logout'], true)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                $uri = str_replace('{id}', '01890000-0000-7000-8000-000000000000', $path);
                $this->client->request(strtoupper($method), $uri, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
                self::assertResponseStatusCodeSame(401, strtoupper($method).' '.$path);
                self::assertContains('401', $this->codes($operation), strtoupper($method).' '.$path);
                ++$checked;
            }
        }
        self::assertGreaterThan(15, $checked);
    }

    public function testAdminCategoryMatchesTheListResponse(): void
    {
        $this->loginAdmin();
        $this->builder()->category('t-contract', $this->builder()->category('t-contract-root'));
        $schema = $this->contract()['components']['schemas']['AdminCategory'];

        $list = $this->getJson('/api/admin/categories');

        self::assertNotEmpty($list);
        foreach ($list as $category) {
            self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($category), $category['slug']);
        }
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), $schema['required']);
    }

    public function testAdminProductPageMatchesTheSchema(): void
    {
        $this->loginAdmin();
        $b = $this->builder();
        $product = $b->product('t-contract-item', $b->category('t-contract'));
        $b->offer($product, 'https://supplier.example/offer/2e04e0431d', 'Seller', $b->variant($product, 'T-CONTRACT-1'));
        $schemas = $this->contract()['components']['schemas'];

        $page = $this->getJson('/api/admin/products', ['q' => 't-contract-item']);

        $item = $schemas['AdminProductPage']['properties']['items']['items'];
        self::assertEqualsCanonicalizing($schemas['AdminProductPage']['required'], array_keys($page));
        self::assertEqualsCanonicalizing($item['required'], array_keys($page['items'][0]));
        self::assertEqualsCanonicalizing($item['properties']['variants']['items']['required'], array_keys($page['items'][0]['variants'][0]));
        self::assertEqualsCanonicalizing($schemas['AdminSupplierOffer']['required'], array_keys($page['items'][0]['supplierOffers'][0]));
    }

    public function testDocumentedStatusCodesMatchTheControllers(): void
    {
        // every code here is produced by the controller or the firewall and covered by the API tests
        $expected = [
            'get /api/products' => ['200', '422'],
            'get /api/products/{slug}' => ['200', '404', '422'],
            'get /api/categories' => ['200', '422'],
            'get /api/categories/{slug}/facets' => ['200', '404', '422'],
            'get /api/account/me' => ['200', '401', '422'],
            'get /api/admin/products' => ['200', '401', '422'],
            'post /api/admin/products' => ['201', '401', '403', '409', '422'],
            'put /api/admin/products/{id}' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/products/{id}/variants' => ['201', '401', '403', '404', '409', '422'],
            'put /api/admin/variants/{id}' => ['200', '401', '403', '404', '409', '422'],
            'get /api/admin/orders' => ['200', '401', '422'],
            'patch /api/admin/orders/{id}/status' => ['200', '401', '403', '404', '409', '422'],
            'post /api/checkout' => ['201', '409', '422'],
            'get /api/admin/pricing-rules' => ['200', '401'],
            'post /api/admin/pricing-rules' => ['201', '401', '403', '409', '422'],
            'put /api/admin/pricing-rules/{id}' => ['200', '401', '403', '404', '409', '422'],
            'delete /api/admin/pricing-rules/{id}' => ['204', '401', '403', '404'],
            'get /api/admin/variants/{id}/pricing' => ['200', '401', '404'],
            'post /api/admin/variants/{id}/pricing/apply' => ['200', '401', '403', '404', '409', '422'],
            'get /api/admin/variants/{id}/price-history' => ['200', '401', '404', '422'],
            'get /api/admin/pricing/reprice' => ['200', '401', '404', '422'],
            'post /api/admin/pricing/reprice' => ['200', '401', '403', '409', '422'],
            'get /api/admin/pricing/alerts' => ['200', '401'],
        ];
        foreach ($expected as $operation => $codes) {
            [$method, $path] = explode(' ', $operation);
            self::assertEqualsCanonicalizing($codes, $this->codes($this->contract()['paths'][$path][$method]), $operation);
        }
    }

    public function testPricingResponsesMatchTheSchemas(): void
    {
        $token = $this->loginAdmin();
        $b = $this->builder();
        $category = $b->category('t-contract');
        $b->rule($category, 0, null, 3000);
        $product = $b->product('t-contract-item', $category);
        $variant = $b->variant($product, 'T-CONTRACT-1');
        $b->pricedOffer($product, $variant, 2000, 'EUR', 25_000_000, 200);
        $id = $variant->getId()->toRfc4122();
        $schemas = $this->contract()['components']['schemas'];

        $panel = $this->getJson('/api/admin/variants/'.$id.'/pricing');
        $this->assertShape($schemas['VariantPricing'], $panel, 'VariantPricing');
        $this->assertShape($schemas['LandedCost'], $panel['cost'], 'LandedCost');
        $this->assertShape($schemas['PriceSuggestion'], $panel['suggestion'], 'PriceSuggestion');
        $this->assertShape($schemas['PricingRule'], $panel['rule'], 'PricingRule');
        self::assertEqualsCanonicalizing([...$schemas['Availability']['required'], ...$schemas['AdminAvailability']['allOf'][1]['required']], array_keys($panel['availability']));
        $this->assertShape($schemas['PricingRule'], $this->getJson('/api/admin/pricing-rules')[0], 'rule list');

        $preview = $this->getJson('/api/admin/pricing/reprice');
        $this->assertShape($schemas['RepricePreview'], $preview, 'RepricePreview');
        $this->assertShape($schemas['RepricePreview']['properties']['rows']['items'], $preview['rows'][0], 'reprice row');
        $applied = $this->sendJson('POST', '/api/admin/pricing/reprice', ['items' => [['variantId' => $id, 'suggestedPriceCzk' => $panel['suggestion']['priceCzk']]]], $token);
        $this->assertShape($schemas['RepriceApplyResult'], $applied, 'RepriceApplyResult');
        $this->assertShape($schemas['PriceApplied'], $applied['items'][0], 'PriceApplied');
        $history = $this->getJson('/api/admin/variants/'.$id.'/price-history');
        $this->assertShape($schemas['PriceHistoryPage'], $history, 'PriceHistoryPage');
        $this->assertShape($schemas['PriceHistoryPage']['properties']['items']['items'], $history['items'][0], 'history item');
        $this->assertShape($schemas['PricingAlerts'], $this->getJson('/api/admin/pricing/alerts'), 'PricingAlerts');

        $card = $this->getJson('/api/products', ['category' => 't-contract'])['items'][0];
        self::assertSame([], array_diff($schemas['ProductCard']['required'], array_keys($card)));
        $this->assertShape($schemas['Availability'], $card['availability'], 'card availability');
        $detailVariant = $this->getJson('/api/products/t-contract-item')['variants'][0];
        self::assertSame([], array_diff($schemas['Product']['allOf'][1]['properties']['variants']['items']['required'], array_keys($detailVariant)));
        $this->assertShape($schemas['Availability'], $detailVariant['availability'], 'variant availability');
    }

    public function testAdminOrderItemsCarryTheDocumentedSnapshot(): void
    {
        $b = $this->builder();
        $product = $b->product('t-contract-order', $b->category('t-contract'));
        $variant = $b->variant($product, 'T-CONTRACT-ORDER');
        $b->pricedOffer($product, $variant, 50000);
        $this->client->request('POST', '/api/checkout', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))], content: json_encode([
            'locale' => 'cs',
            'customer' => ['name' => 'Rider', 'email' => 'rider@example.test', 'country' => 'CZ', 'address' => 'Demo 1', 'postalCode' => '11000', 'district' => 'Praha 1'],
            'items' => [['variantId' => $variant->getId()->toRfc4122(), 'quantity' => 1]],
        ], JSON_THROW_ON_ERROR));
        $receipt = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $schemas = $this->contract()['components']['schemas'];
        $this->assertShape($schemas['ReceiptItem'], $receipt['items'][0], 'ReceiptItem');

        $this->loginAdmin();
        $order = $this->getJson('/api/admin/orders/'.$this->db()->fetchOne('SELECT id FROM shop_order WHERE reference = :ref', ['ref' => $receipt['reference']]));

        self::assertEqualsCanonicalizing([...$schemas['ReceiptItem']['required'], ...$schemas['AdminOrderItem']['allOf'][1]['required']], array_keys($order['items'][0]));
        self::assertSame([], array_diff([...$schemas['OrderReceipt']['required'], ...$schemas['AdminOrder']['allOf'][1]['required']], array_keys($order)));
    }

    public function testErrorShapeAndEanInputAreDescribed(): void
    {
        $schemas = $this->contract()['components']['schemas'];

        self::assertSame(['field', 'message'], $schemas['Problem']['properties']['violations']['items']['required']);
        $ean = $schemas['VariantWriteRequest']['properties']['ean'];
        // the API strips spaces and dashes, so a digits-only pattern would reject valid input
        self::assertArrayNotHasKey('pattern', $ean);
        self::assertStringContainsString('dashes', $ean['description']);
    }

    /** Response keys are exactly the documented ones, and every documented one is required. */
    private function assertShape(array $schema, array $actual, string $label): void
    {
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), $schema['required'], $label.' schema');
        self::assertEqualsCanonicalizing($schema['required'], array_keys($actual), $label);
    }

    /** @return list<string> */
    private function codes(array $operation): array
    {
        return array_map('strval', array_keys($operation['responses']));
    }

    private function contract(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 2).'/config/api_doc/shop.yaml');
    }
}
