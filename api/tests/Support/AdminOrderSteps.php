<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\CustomerAccount;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Steps for API tests that walk orders through the admin: variants to order, admin actions and payments, lookups in
 * the admin order view. For ApiTestCase subclasses; set $token with loginAdmin() first.
 */
trait AdminOrderSteps
{
    private const AGREED = ['customerAgreedVia' => ['channel' => 'whatsapp', 'note' => 'Customer said yes']];
    private const WINDOW = ['from' => '2026-10-01T17:00:00+02:00', 'to' => '2026-10-01T19:00:00+02:00'];

    private string $token = '';

    /** POST an admin action or correction and check the status; returns the order, or the problem. */
    private function act(string $id, string $action, array $payload = [], int $status = 200): array
    {
        $result = $this->sendJson('POST', '/api/admin/orders/'.$id.'/'.$action, $payload, $this->token);
        self::assertResponseStatusCodeSame($status, $action.': '.json_encode($result));

        return $result;
    }

    private function pay(string $id, int $amount, string $kind = 'payment', string $method = 'cash', int $status = 201, ?string $claimId = null): array
    {
        $body = ['kind' => $kind, 'method' => $method, 'amountMinor' => $amount];
        if ($claimId !== null) {
            $body['claimId'] = $claimId;
        }
        $result = $this->sendJson('POST', '/api/admin/orders/'.$id.'/payments', $body, $this->token, ['HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(12))]);
        self::assertResponseStatusCodeSame($status, json_encode($result));

        return $result;
    }

    private function order(string $id): array
    {
        return $this->getJson('/api/admin/orders/'.$id);
    }

    private function line(array $order, string $sku): array
    {
        foreach ($order['items'] as $item) {
            if ($item['sku'] === $sku) {
                return $item;
            }
        }
        self::fail('No line '.$sku);
    }

    /** Checkout of these variants as a guest; returns the order id. */
    private function placeOrder(array $items, string $fulfilment = 'together', array $customer = [], string $method = 'prague_personal'): string
    {
        $receipt = $this->checkout($items, $method, $fulfilment, $customer);
        self::assertResponseStatusCodeSame(201, json_encode($receipt));

        return $this->orderId($receipt['reference']);
    }

    /** A variant bought from a supplier: an offer with the given supplier, price and lead time 2-5 days. */
    private function offered(string $sku, int $priceCzk, string $supplier = 'bike24', int $offerPrice = 20000, string $currency = 'CZK', ?int $fxRateCzk = null, int $inboundShipping = 0, ?int $leadTimeMaxDays = 5): string
    {
        $b = $this->builder();
        $product = $b->product(strtolower($sku), $b->category(strtolower($sku).'-cat'));
        $variant = $b->variant($product, $sku, priceCzk: $priceCzk);
        $offer = $b->pricedOffer($product, $variant, $offerPrice, $currency, $fxRateCzk, $inboundShipping, $leadTimeMaxDays === null ? null : 2, $leadTimeMaxDays);
        if ($supplier !== 'bike24') {
            $offer->update($supplier, $offer->getUrl(), 'Listing '.$sku, $offer->getSeller(), $currency, $offerPrice, 4, $offer->getCheckedAt(), $leadTimeMaxDays === null ? null : 2, $leadTimeMaxDays, $variant, 'matched');
            static::getContainer()->get(EntityManagerInterface::class)->flush();
        }

        return $variant->getId()->toRfc4122();
    }

    /** A variant NODRA holds itself. */
    private function held(string $sku, int $priceCzk, int $stock = 1): string
    {
        $b = $this->builder();
        $product = $b->product(strtolower($sku), $b->category(strtolower($sku).'-cat'));

        return $b->variant($product, $sku, stock: $stock, priceCzk: $priceCzk)->getId()->toRfc4122();
    }

    private function asCustomer(string $sub): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $account = new CustomerAccount($sub, 'rider@example.test', 'Rider');
        $em->persist($account);
        $em->flush();
        $this->signInCustomer($account);
    }

    private function points(): int
    {
        return (int) $this->db()->fetchOne("SELECT COALESCE(SUM(e.points), 0) FROM loyalty_entry e JOIN customer_account a ON a.id = e.account_id WHERE a.email = 'rider@example.test'");
    }

    /** @return list<string> */
    private function eventTypes(array $order): array
    {
        return array_column($order['events'], 'type');
    }
}
