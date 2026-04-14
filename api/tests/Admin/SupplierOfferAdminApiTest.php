<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Tests\Support\ApiTestCase;

final class SupplierOfferAdminApiTest extends ApiTestCase
{
    private const URL = 'https://supplier.example/offer/04099262cc';

    private string $token;
    private string $productId;
    private string $variantA;
    private string $variantB;
    private string $foreignVariant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
        $b = $this->builder();
        $chains = $b->category('t-chains');
        $product = $b->product('t-chain', $chains, 'KMC');
        $this->productId = $product->getId()->toRfc4122();
        $this->variantA = $b->variant($product, 'T-CHAIN-SILVER')->getId()->toRfc4122();
        $this->variantB = $b->variant($product, 'T-CHAIN-BLACK')->getId()->toRfc4122();
        $this->foreignVariant = $b->variant($b->product('t-other', $chains), 'T-OTHER-1')->getId()->toRfc4122();
    }

    public function testOfferIsRecordedAndShownToTheAdmin(): void
    {
        $created = $this->sendJson('POST', $this->offersUri(), $this->payload([
            'seller' => ' Velo Shop ', 'leadTimeMinDays' => 2, 'leadTimeMaxDays' => 5, 'reportedQuantity' => 7,
            'variantId' => $this->variantA, 'verificationStatus' => 'matched',
        ]), $this->token);

        self::assertResponseStatusCodeSame(201);
        $offers = $this->adminOffers();
        self::assertCount(1, $offers);
        $offer = $offers[0];
        self::assertSame($created['id'], $offer['id']);
        self::assertSame([
            'variantId' => $this->variantA, 'seller' => 'Velo Shop', 'leadTimeMinDays' => 2, 'leadTimeMaxDays' => 5,
            'supplier' => 'allegro_cz', 'url' => self::URL, 'title' => 'KMC X12 chain', 'currency' => 'CZK', 'priceMinor' => 129900,
            'reportedQuantity' => 7, 'verificationStatus' => 'matched',
        ], array_diff_key($offer, ['id' => true, 'checkedAt' => true]));
        // ISO 8601 with the offset, the same moment that was sent
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $offer['checkedAt']);
        self::assertEquals(new \DateTimeImmutable('2026-09-20T10:00:00+02:00'), new \DateTimeImmutable($offer['checkedAt']));

        // unmatching an offer keeps it at product level
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$created['id'], $this->payload(['verificationStatus' => 'rejected']), $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame([null, 'rejected', null], [$this->adminOffers()[0]['variantId'], $this->adminOffers()[0]['verificationStatus'], $this->adminOffers()[0]['seller']]);
    }

    public function testMatchedOfferNeedsAVariantOfTheSameProduct(): void
    {
        $this->sendJson('POST', $this->offersUri(), $this->payload(['verificationStatus' => 'matched']), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', $this->offersUri(), $this->payload(['variantId' => $this->foreignVariant]), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', $this->offersUri(), $this->payload(['leadTimeMinDays' => 9, 'leadTimeMaxDays' => 3]), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', $this->offersUri(), $this->payload(['checkedAt' => '+3 days']), $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('POST', $this->offersUri(), $this->payload(['variantId' => '01890000-0000-7000-8000-000000000000']), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->adminOffers());

        $offer = $this->sendJson('POST', $this->offersUri(), $this->payload(), $this->token);
        self::assertResponseStatusCodeSame(201);
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$offer['id'], $this->payload(['verificationStatus' => 'matched']), $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('snapshot', $this->adminOffers()[0]['verificationStatus']);
    }

    public function testTheSameListingIsRecordedOncePerProductOrVariant(): void
    {
        $this->sendJson('POST', $this->offersUri(), $this->payload(), $this->token);
        self::assertResponseStatusCodeSame(201);
        $this->sendJson('POST', $this->offersUri(), $this->payload(['title' => 'Again']), $this->token);
        self::assertResponseStatusCodeSame(409);

        // variant-level rows are separate from the product-level one and from each other
        $matchedA = $this->sendJson('POST', $this->offersUri(), $this->payload(['variantId' => $this->variantA, 'verificationStatus' => 'matched']), $this->token);
        self::assertResponseStatusCodeSame(201);
        $this->sendJson('POST', $this->offersUri(), $this->payload(['variantId' => $this->variantA]), $this->token);
        self::assertResponseStatusCodeSame(409);
        $matchedB = $this->sendJson('POST', $this->offersUri(), $this->payload(['variantId' => $this->variantB, 'verificationStatus' => 'matched']), $this->token);
        self::assertResponseStatusCodeSame(201);

        // moving B's row onto A would duplicate A's listing
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$matchedB['id'], $this->payload(['variantId' => $this->variantA, 'verificationStatus' => 'matched']), $this->token);
        self::assertResponseStatusCodeSame(409);
        // saving a row unchanged is not a clash with itself
        $this->sendJson('PUT', '/api/admin/supplier-offers/'.$matchedA['id'], $this->payload(['variantId' => $this->variantA, 'verificationStatus' => 'matched', 'priceMinor' => 119900]), $this->token);
        self::assertResponseIsSuccessful();

        self::assertCount(3, $this->adminOffers());
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM supplier_offer WHERE url = :url', ['url' => self::URL]));
    }

    public function testUnknownProductOrOfferIsNotFound(): void
    {
        $this->sendJson('POST', '/api/admin/products/01890000-0000-7000-8000-000000000000/supplier-offers', $this->payload(), $this->token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('PUT', '/api/admin/supplier-offers/01890000-0000-7000-8000-000000000000', $this->payload(), $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    private function offersUri(): string
    {
        return '/api/admin/products/'.$this->productId.'/supplier-offers';
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'supplier' => 'allegro_cz', 'url' => self::URL, 'title' => 'KMC X12 chain', 'currency' => 'CZK',
            'priceMinor' => 129900, 'checkedAt' => '2026-09-20T10:00:00+02:00', 'verificationStatus' => 'snapshot',
        ];
    }

    private function adminOffers(): array
    {
        $page = $this->getJson('/api/admin/products', ['q' => 't-chain']);

        return array_column($page['items'], null, 'slug')['t-chain']['supplierOffers'];
    }
}
