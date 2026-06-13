<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Tests\Support\ApiTestCase;

/** The dashboard's own stock, the order settings and the login error. */
final class AdminOverviewTest extends ApiTestCase
{
    public function testDashboardListsOnlyWhatNodraHolds(): void
    {
        $b = $this->builder();
        $published = $b->product('t-own-stock', $b->category('t-own-stock-cat'));
        $b->variant($published, 'T-OWN-3', stock: 3);
        $b->variant($published, 'T-OWN-0', stock: 0);
        $draft = $b->product('t-own-draft', $b->category('t-own-draft-cat'), status: 'draft');
        $b->variant($draft, 'T-OWN-DRAFT', stock: 1, active: false);
        $this->loginAdmin();

        $dashboard = $this->getJson('/api/admin/dashboard');

        $skus = array_column($dashboard['ownStock'], 'stock', 'sku');
        self::assertSame(['T-OWN-DRAFT' => 1, 'T-OWN-3' => 3], array_intersect_key($skus, array_flip(['T-OWN-DRAFT', 'T-OWN-3', 'T-OWN-0'])), 'goods on the shelf, whatever the card state');
        self::assertSame([], array_filter($dashboard['ownStock'], static fn (array $row): bool => $row['stock'] < 1));
        self::assertSame((int) $this->db()->fetchOne('SELECT COUNT(*) FROM product_variant WHERE stock > 0'), $dashboard['ownStockVariants']);
        self::assertArrayNotHasKey('lowStock', $dashboard);
        self::assertSame(['review', 'problem', 'to_purchase', 'waiting', 'delayed', 'to_schedule', 'delivering', 'unpaid', 'refund'], array_keys($dashboard['queues']));
    }

    public function testSettingsTellTheAcceptedPaymentMethodsFeesAndNotes(): void
    {
        $this->loginAdmin();

        $settings = $this->getJson('/api/admin/settings');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'currency' => 'CZK',
            'payment' => ['handoverMethods' => ['cash', 'bank_transfer'], 'paymentMethods' => ['cash', 'bank_transfer'], 'refundMethods' => ['cash', 'bank_transfer']],
            'delivery' => [
                'methods' => ['pickup_andel', 'prague_personal'], 'pragueFeeMinor' => 14900, 'pragueFreeFromMinor' => 50000,
                'carrierFeeMinor' => null, 'carrierCodFeeMinor' => null,
                'pickupNote' => [
                    'cs' => 'Místo a čas předání na Andělu domluvíme zprávou.',
                    'de' => 'Ort und Zeit der Übergabe am Anděl vereinbaren wir per Nachricht.',
                    'en' => 'We agree the place and time at Anděl by message.',
                ],
            ],
            'privacyVersion' => 'draft-2026-09',
        ], $settings);
    }

    public function testAWrongPasswordIsAProblemLikeEveryOtherError(): void
    {
        $this->loginAdmin();
        $this->client->request('POST', '/api/admin/logout');

        foreach (['test-admin@nodra.test' => 'wrong', 'nobody@nodra.test' => 'test-password'] as $email => $password) {
            $this->client->request('POST', '/api/admin/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => $password]));
            self::assertResponseStatusCodeSame(401);
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertSame(['message' => 'Invalid email or password', 'code' => 'invalid_credentials'], json_decode((string) $this->client->getResponse()->getContent(), true), $email);
        }
    }
}
