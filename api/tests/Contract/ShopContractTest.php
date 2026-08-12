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
                $uri = preg_replace('/\{[A-Za-z]+\}/', '01890000-0000-7000-8000-000000000000', $path);
                $this->client->request(strtoupper($method), $uri, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
                self::assertResponseStatusCodeSame(401, strtoupper($method).' '.$path);
                self::assertContains('401', $this->codes($operation), strtoupper($method).' '.$path);
                ++$checked;
            }
        }
        self::assertGreaterThan(30, $checked);
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
            'get /api/admin/orders/{id}' => ['200', '401', '404'],
            'post /api/admin/orders/{id}/confirm' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/cancel' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/terms' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/ordered' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/received' => ['200', '401', '403', '404', '409'],
            'post /api/admin/orders/{id}/items/{itemId}/failed' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/cancel' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/replacement' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/return' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/shipments/{shipmentId}/schedule' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/shipments/{shipmentId}/hand-over' => ['200', '401', '403', '404', '409'],
            'post /api/admin/orders/{id}/shipments/{shipmentId}/refused' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/payments' => ['201', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/move' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/items/{itemId}/undo-received' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/shipments/{shipmentId}/reschedule' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/orders/{id}/payments/{paymentId}/void' => ['200', '401', '403', '404', '409', '422'],
            'get /api/admin/dashboard' => ['200', '401'],
            'get /api/admin/queues' => ['200', '401'],
            'get /api/admin/settings' => ['200', '401'],
            'get /api/admin/to-purchase' => ['200', '401'],
            'get /api/admin/purchases' => ['200', '401', '422'],
            'post /api/admin/purchases' => ['201', '401', '403', '409', '422'],
            'get /api/admin/purchases/{id}' => ['200', '401', '404'],
            'post /api/admin/purchases/{id}/receive' => ['200', '401', '403', '404', '409'],
            'post /api/admin/purchases/{id}/cancel' => ['200', '401', '403', '404', '409', '422'],
            'get /api/admin/products/{id}/external-ratings' => ['200', '401', '404'],
            'post /api/admin/products/{id}/external-ratings' => ['201', '401', '403', '404', '409', '422'],
            'put /api/admin/external-ratings/{id}' => ['200', '401', '403', '404', '422'],
            'delete /api/admin/external-ratings/{id}' => ['204', '401', '403', '404'],
            'get /api/admin/claims' => ['200', '401', '422'],
            'post /api/admin/claims' => ['201', '401', '403', '404', '409', '422'],
            'get /api/admin/claims/{id}' => ['200', '401', '404'],
            'post /api/admin/claims/{id}/wait' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/claims/{id}/accept' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/claims/{id}/reject' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/claims/{id}/resolve' => ['200', '401', '403', '404', '409', '422'],
            'post /api/admin/login' => ['200', '401'],
            'post /api/checkout/quote' => ['200', '422'],
            'post /api/checkout' => ['201', '409', '422'],
            'get /api/orders/{reference}' => ['200', '404'],
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
        // the old one-field status change is gone, actions replaced it
        self::assertArrayNotHasKey('/api/admin/orders/{id}/status', $this->contract()['paths']);
        $this->client->request('PATCH', '/api/admin/orders/01890000-0000-7000-8000-000000000000/status', server: ['CONTENT_TYPE' => 'application/json'], content: '{"status":"processing"}');
        self::assertResponseStatusCodeSame(404);
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

    public function testOrderResponsesMatchTheSchemas(): void
    {
        $b = $this->builder();
        $product = $b->product('t-contract-order', $b->category('t-contract'));
        $offered = $b->variant($product, 'T-CONTRACT-ORDER');
        $b->pricedOffer($product, $offered, 50000);
        $held = $b->variant($product, 'T-CONTRACT-HELD', stock: 2);
        $items = [$offered->getId()->toRfc4122() => 1, $held->getId()->toRfc4122() => 1];
        $schemas = $this->contract()['components']['schemas'];

        $quote = $this->quote($items);
        $this->assertShape($schemas['CheckoutQuote'], $quote, 'CheckoutQuote');
        $this->assertShape($schemas['CheckoutQuote']['properties']['lines']['items'], $quote['lines'][0], 'quote line');
        $this->assertShape($schemas['CheckoutQuote']['properties']['methods']['items'], $quote['methods'][0], 'quote method');
        $this->assertShape($schemas['DeliveryOption'], $quote['options']['split'], 'DeliveryOption');
        $this->assertShape($schemas['DeliveryOption']['properties']['shipments']['items'], $quote['options']['split']['shipments'][0], 'quote shipment');

        $receipt = $this->checkout($items, fulfilment: 'split');
        self::assertResponseStatusCodeSame(201);
        $this->assertShape($schemas['OrderReceipt'], $receipt, 'OrderReceipt');
        $this->assertShape($schemas['ReceiptItem'], $receipt['items'][0], 'ReceiptItem');
        $this->assertShape($schemas['OrderReceipt']['properties']['shipments']['items'], $receipt['shipments'][0], 'receipt shipment');

        $token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $this->sendJson('POST', '/api/admin/orders/'.$id.'/confirm', ['customerAgreedVia' => ['channel' => 'whatsapp', 'note' => 'OK']], $token);
        $order = $this->sendJson('POST', '/api/admin/orders/'.$id.'/payments', ['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 100], $token, ['HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame(201);
        $this->assertShape($schemas['AdminOrder'], $order['order'], 'AdminOrder');
        $this->assertShape($schemas['AdminOrderItem'], $order['order']['items'][0], 'AdminOrderItem');
        $this->assertShape($schemas['AdminShipment'], $order['order']['shipments'][0], 'AdminShipment');
        $this->assertShape($schemas['AdminPayment'], $order['payment'], 'AdminPayment');
        $this->assertShape($schemas['AdminOrder']['properties']['events']['items'], $order['order']['events'][0], 'order event');
        $this->assertShape($schemas['AdminOrder']['properties']['customer'], $order['order']['customer'], 'customer');
        $this->assertShape($schemas['AdminOrder']['properties']['consents'], $order['order']['consents'], 'consents');

        $this->assertShape($schemas['OrderEconomics'], $order['order']['economics'], 'OrderEconomics');
        $this->assertShape($schemas['OfferSource'], array_values(array_filter(array_column($order['order']['items'], 'offer')))[0], 'OfferSource');

        $page = $this->getJson('/api/admin/orders');
        $this->assertShape($schemas['AdminOrderPage'], $page, 'AdminOrderPage');
        $this->assertShape($schemas['AdminOrderRow'], $page['items'][0], 'admin order row');
        $this->assertShape($schemas['QueueCounts'], $this->getJson('/api/admin/queues'), 'QueueCounts');
        self::assertSame($schemas['OrderQueue']['enum'], array_keys($this->getJson('/api/admin/queues')));

        $dashboard = $this->getJson('/api/admin/dashboard');
        $this->assertShape($schemas['AdminDashboard'], $dashboard, 'AdminDashboard');
        $this->assertShape($schemas['AdminDashboard']['properties']['ownStock']['items'], $dashboard['ownStock'][0], 'own stock row');
        $this->assertShape($schemas['AdminOrderRow'], $dashboard['recentOrders'][0], 'recent order');

        $settings = $this->getJson('/api/admin/settings');
        $this->assertShape($schemas['AdminSettings'], $settings, 'AdminSettings');
        $this->assertShape($schemas['AdminSettings']['properties']['payment'], $settings['payment'], 'settings payment');
        $this->assertShape($schemas['AdminSettings']['properties']['delivery'], $settings['delivery'], 'settings delivery');
        $this->assertShape($schemas['AdminSettings']['properties']['delivery']['properties']['pickupNote'], $settings['delivery']['pickupNote'], 'pickup notes');
    }

    public function testPurchaseResponsesMatchTheSchemas(): void
    {
        $b = $this->builder();
        $product = $b->product('t-contract-buy', $b->category('t-contract'));
        $variant = $b->variant($product, 'T-CONTRACT-BUY');
        $b->pricedOffer($product, $variant, 2000, 'EUR', 25_000_000, 200);
        $receipt = $this->checkout([$variant->getId()->toRfc4122() => 1]);
        $token = $this->loginAdmin();
        $id = $this->orderId($receipt['reference']);
        $this->sendJson('POST', '/api/admin/orders/'.$id.'/confirm', ['customerAgreedVia' => ['channel' => 'whatsapp', 'note' => 'OK']], $token);
        $schemas = $this->contract()['components']['schemas'];

        $toPurchase = $this->getJson('/api/admin/to-purchase');
        $this->assertShape($schemas['ToPurchase'], $toPurchase, 'ToPurchase');
        $this->assertShape($schemas['ToPurchase']['properties']['groups']['items'], $toPurchase['groups'][0], 'to purchase group');
        $this->assertShape($schemas['ToPurchaseLine'], $toPurchase['groups'][0]['lines'][0], 'ToPurchaseLine');
        $this->assertShape($schemas['OfferSource'], $toPurchase['groups'][0]['lines'][0]['offer'], 'to purchase offer');

        $purchase = $this->sendJson('POST', '/api/admin/purchases', [
            'supplier' => 'bike24', 'reference' => 'B24-CONTRACT', 'currency' => 'EUR', 'fxRateCzk' => 25_000_000,
            'lines' => [['itemId' => $toPurchase['groups'][0]['lines'][0]['itemId'], 'unitPriceMinor' => 2000]],
        ], $token, ['HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame(201);
        $this->assertShape($schemas['AdminPurchase'], $purchase, 'AdminPurchase');
        $this->assertShape($schemas['AdminPurchase']['properties']['lines']['items'], $purchase['lines'][0], 'purchase line');
        $page = $this->getJson('/api/admin/purchases');
        $this->assertShape($schemas['AdminPurchasePage'], $page, 'AdminPurchasePage');
        $this->assertShape($schemas['AdminPurchasePage']['properties']['items']['items'], $page['items'][0], 'purchase row');

        $order = $this->getJson('/api/admin/orders/'.$id);
        $this->assertShape($schemas['AdminOrderItem']['properties']['purchase'], $order['items'][0]['purchase'], 'line purchase');
    }

    public function testNewRequestBodiesMatchTheirDtos(): void
    {
        $schemas = $this->contract()['components']['schemas'];
        foreach ([
            'CreatePurchaseRequest' => \App\Admin\CreatePurchaseRequest::class, 'PurchaseLineRequest' => \App\Admin\PurchaseLineRequest::class,
            'MoveLineRequest' => \App\Admin\MoveLineRequest::class, 'RescheduleShipmentRequest' => \App\Admin\RescheduleShipmentRequest::class,
            'ClaimOpenRequest' => \App\Admin\ClaimOpenRequest::class, 'ClaimWaitRequest' => \App\Admin\ClaimWaitRequest::class,
            'ClaimAcceptRequest' => \App\Admin\ClaimAcceptRequest::class, 'ClaimResolveRequest' => \App\Admin\ClaimResolveRequest::class,
            'ExternalRatingWriteRequest' => \App\Admin\ExternalRatingWriteRequest::class,
        ] as $schema => $class) {
            $parameters = (new \ReflectionMethod($class, '__construct'))->getParameters();
            self::assertEqualsCanonicalizing(array_keys($schemas[$schema]['properties']), array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters), $schema);
            $required = array_filter($parameters, static fn (\ReflectionParameter $p): bool => !$p->isOptional());
            self::assertEqualsCanonicalizing($schemas[$schema]['required'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $required), $schema.' required');
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
