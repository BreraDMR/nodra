<?php

declare(strict_types=1);

namespace App\Tests\Order;

use App\Tests\Support\ApiTestCase;

final class PaymentLedgerTest extends ApiTestCase
{
    private string $token;
    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $product = $this->builder()->product('t-ledger', $this->builder()->category('t-ledger-cat'));
        $variant = $this->builder()->variant($product, 'T-LEDGER', stock: 1, priceCzk: 60000);
        $receipt = $this->checkout([$variant->getId()->toRfc4122() => 1]);
        $this->token = $this->loginAdmin();
        $this->orderId = $this->orderId($receipt['reference']);
        $this->sendJson('POST', '/api/admin/orders/'.$this->orderId.'/confirm', ['customerAgreedVia' => ['channel' => 'phone', 'note' => 'OK']], $this->token);
        self::assertResponseIsSuccessful();
    }

    public function testSameKeyReturnsTheFirstEntryAndAnotherBodyConflicts(): void
    {
        $key = bin2hex(random_bytes(12));
        $first = $this->record(['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 20000, 'note' => 'At the door'], $key);
        self::assertResponseStatusCodeSame(201);
        $again = $this->record(['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 20000, 'note' => 'At the door'], $key);
        self::assertResponseStatusCodeSame(201);

        self::assertSame($first['payment'], $again['payment']);
        self::assertSame(['partially_paid', 40000], [$again['order']['paymentStatus'], $again['order']['amountDue']['amount']]);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = :id', ['id' => $this->orderId]));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM order_event WHERE order_id = :id AND type = 'payment_recorded'", ['id' => $this->orderId]));
        self::assertSame(['payment', 'cash', 20000, 'test-admin@nodra.test', 'At the door'], [$first['payment']['kind'], $first['payment']['method'], $first['payment']['amount']['amount'], $first['payment']['recordedBy'], $first['payment']['note']]);

        $error = $this->record(['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 25000], $key);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('idempotency_conflict', $error['code']);
    }

    public function testMoneyAboveTheTotalOrWithoutAKeyIsRefused(): void
    {
        $error = $this->record(['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 60001]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['overpayment', 60000], [$error['code'], $error['amountDueMinor']]);

        $error = $this->record(['kind' => 'payment', 'method' => 'card', 'amountMinor' => 1000]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('method_not_accepted', $error['code']);
        $this->record(['kind' => 'payment', 'method' => 'carrier_cod', 'amountMinor' => 1000]);
        self::assertResponseStatusCodeSame(422);

        $error = $this->record(['kind' => 'refund', 'method' => 'cash', 'amountMinor' => 1000]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['action_not_allowed', 'record_refund'], [$error['code'], $error['action']]);

        $error = $this->record(['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 1000], '');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_idempotency_key', $error['code']);

        $this->record(['kind' => 'payment', 'method' => 'cash', 'amountMinor' => 1000, 'shipmentId' => '01890000-0000-7000-8000-000000000000']);
        self::assertResponseStatusCodeSame(404);

        $this->record(['kind' => 'payment', 'method' => 'bank_transfer', 'amountMinor' => 60000]);
        self::assertResponseStatusCodeSame(201);
        $error = $this->record(['kind' => 'refund', 'method' => 'cash', 'amountMinor' => 60001]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('refund_exceeds_paid', $error['code']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM payment WHERE order_id = :id', ['id' => $this->orderId]));
    }

    private function record(array $payload, ?string $key = null): array
    {
        return $this->sendJson('POST', '/api/admin/orders/'.$this->orderId.'/payments', $payload, $this->token, ['HTTP_IDEMPOTENCY_KEY' => $key ?? bin2hex(random_bytes(12))]);
    }
}
