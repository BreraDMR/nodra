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
        ];
        foreach ($expected as $operation => $codes) {
            [$method, $path] = explode(' ', $operation);
            self::assertEqualsCanonicalizing($codes, $this->codes($this->contract()['paths'][$path][$method]), $operation);
        }
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
