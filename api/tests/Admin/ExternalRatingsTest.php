<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * D07.3: outside ratings are typed in by hand and stay in the admin — the
 * public API never shows them, and no rating is invented anywhere in code.
 */
final class ExternalRatingsTest extends ApiTestCase
{
    private string $token = '';
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginAdmin();
        $b = $this->builder();
        $product = $b->product('t-rated-light', $b->category('t-rated-cat'));
        $b->variant($product, 'T-RATED-1');
        $this->productId = $product->getId()->toRfc4122();
    }

    /** @param array<string, mixed> $payload */
    private function create(array $payload, int $status = 201): array
    {
        $result = $this->sendJson('POST', '/api/admin/products/'.$this->productId.'/external-ratings', $payload, $this->token);
        self::assertResponseStatusCodeSame($status, json_encode($result));

        return $result;
    }

    private function sample(array $over = []): array
    {
        return array_merge(['source' => 'bike-components', 'model' => 'Buster 800', 'rating' => 4.5, 'ratingScale' => 5, 'ratingCount' => 37, 'checkedAt' => '2026-09-29', 'url' => 'https://example.test/buster'], $over);
    }

    public function testCreateListUpdateDelete(): void
    {
        $rating = $this->create($this->sample());
        $this->assertRatingShape($rating);

        $page = $this->getJson('/api/admin/products/'.$this->productId.'/external-ratings');
        self::assertSame(['items', 'total'], array_keys($page));
        self::assertSame(1, $page['total']);
        self::assertSame([$rating['id']], array_column($page['items'], 'id'));

        $updated = $this->sendJson('PUT', '/api/admin/external-ratings/'.$rating['id'], $this->sample(['rating' => 4.7, 'ratingCount' => 41, 'checkedAt' => '2026-09-30']), $this->token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([4.7, 41, '2026-09-30'], [$updated['rating'], $updated['ratingCount'], $updated['checkedAt']]);

        $this->sendJson('DELETE', '/api/admin/external-ratings/'.$rating['id'], [], $this->token);
        self::assertResponseStatusCodeSame(204);
        // after the delete the same source and model is free again
        $this->create($this->sample());
    }

    public function testOneRatingPerSourceAndModel(): void
    {
        $this->create($this->sample());
        $problem = $this->create($this->sample(['rating' => 4.9]), 409);
        self::assertSame('rating_exists', $problem['code']);
        // a second source is fine: sources are never merged without accounting for duplicates
        $this->create($this->sample(['source' => 'heureka']));
        self::assertSame(2, $this->getJson('/api/admin/products/'.$this->productId.'/external-ratings')['total']);
    }

    public function testRatingsStayInsideTheirProduct(): void
    {
        $rating = $this->create($this->sample());
        $b = $this->builder();
        $other = $b->product('t-rated-other', $b->category('t-rated-cat-2'));
        $b->variant($other, 'T-RATED-2');

        $page = $this->getJson('/api/admin/products/'.$other->getId()->toRfc4122().'/external-ratings');
        self::assertSame([], $page['items']);
    }

    public function testRubbishIsRefusedAndForeignIdsNotFound(): void
    {
        // the rating must sit between zero and its scale
        $this->create($this->sample(['rating' => 5.5]), 422);
        $this->create($this->sample(['ratingCount' => -1]), 422);
        $this->create($this->sample(['source' => 'Bike Components']), 422);
        $this->create($this->sample(['checkedAt' => '29.09.2026']), 422);

        $this->sendJson('POST', '/api/admin/products/01890000-0000-7000-8000-000000000000/external-ratings', $this->sample(), $this->token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('PUT', '/api/admin/external-ratings/01890000-0000-7000-8000-000000000000', $this->sample(), $this->token);
        self::assertResponseStatusCodeSame(404);
        $this->sendJson('DELETE', '/api/admin/external-ratings/not-a-uuid', [], $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWritesNeedTheCsrfTokenAndThePublicApiStaysQuiet(): void
    {
        $this->sendJson('POST', '/api/admin/products/'.$this->productId.'/external-ratings', $this->sample());
        self::assertResponseStatusCodeSame(403);

        $this->create($this->sample());

        // the public product page never sees a rating
        $this->client->request('GET', '/api/products/t-rated-light', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $body = $this->decode();
        self::assertArrayNotHasKey('externalRatings', $body);
        self::assertArrayNotHasKey('ratings', $body);
        self::assertStringNotContainsString('bike-components', json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function assertRatingShape(array $rating): void
    {
        $schema = Yaml::parseFile(__DIR__.'/../../config/api_doc/shop.yaml')['components']['schemas']['AdminExternalRating'];
        self::assertEqualsCanonicalizing($schema['required'], array_keys($rating), 'AdminExternalRating shape');
    }
}
