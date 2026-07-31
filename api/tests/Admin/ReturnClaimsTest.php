<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Order\OrderQueues;
use App\Tests\Support\AdminOrderSteps;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The after-sale claims registry (D08.4): windows from handover, the lifecycle, the money rule that a refund claim
 * only closes when the ledger holds it, and the claim's place in the order, dashboard and public responses.
 */
final class ReturnClaimsTest extends ApiTestCase
{
    use AdminOrderSteps;

    private function pragueToday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(OrderQueues::TIMEZONE));
    }

    /** A completed paid order of one held variant, handed over today; returns [orderId, itemId]. */
    private function completedOrder(int $priceCzk = 30000): array
    {
        $held = $this->held('T-RC-HELD', $priceCzk, 1);
        $id = $this->placeOrder([$held => 1]);
        if ($this->token === '') { $this->token = $this->loginAdmin(); }
        $order = $this->act($id, 'confirm', ['customerAgreedVia' => ['channel' => 'whatsapp', 'note' => 'Yes']]);
        $this->act($id, 'shipments/'.$order['shipments'][0]['id'].'/schedule', $this::WINDOW);
        $this->act($id, 'shipments/'.$order['shipments'][0]['id'].'/hand-over');
        $order = $this->pay($id, $order['total']['amount'])['order'];
        self::assertSame('completed', $order['status']);
        $line = $this->line($order, 'T-RC-HELD');

        return [$id, $line['id']];
    }

    /** @param array<string, mixed> $payload */
    private function claim(array $payload, int $status = 201): array
    {
        $result = $this->sendJson('POST', '/api/admin/claims', $payload, $this->token);
        self::assertResponseStatusCodeSame($status, json_encode($result));

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function claimAction(string $claimId, string $action, array $payload = [], int $status = 200): array
    {
        $result = $this->sendJson('POST', '/api/admin/claims/'.$claimId.'/'.$action, $payload, $this->token);
        self::assertResponseStatusCodeSame($status, $action.': '.json_encode($result));

        return $result;
    }

    private function claimById(string $claimId): array
    {
        return $this->getJson('/api/admin/claims/'.$claimId);
    }

    public function testOpeningNeedsHandedOverGoods(): void
    {
        $held = $this->held('T-RC-NEW', 30000, 1);
        $receipt = $this->checkout([$held => 1]);
        self::assertResponseStatusCodeSame(201);
        if ($this->token === '') { $this->token = $this->loginAdmin(); }
        $id = $this->orderId($receipt['reference']);

        $this->claim(['orderId' => $id, 'kind' => 'return'], 422);

        $order = $this->act($id, 'confirm', ['customerAgreedVia' => ['channel' => 'whatsapp', 'note' => 'Yes']]);
        $this->claim(['orderId' => $id, 'itemId' => $order['items'][0]['id'], 'kind' => 'warranty'], 422);
    }

    public function testWindowsCountFromHandover(): void
    {
        [$id, $itemId] = $this->completedOrder();
        $today = $this->pragueToday()->format('Y-m-d');
        $in14Days = $this->pragueToday()->modify('+14 days')->format('Y-m-d');

        $return = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);
        self::assertSame(['open', $today, $in14Days, true], [$return['status'], $return['handoverDate'], $return['windowEnd'], $return['onTime']]);
        self::assertNull($return['dueAt']);

        $warranty = $this->claim(['orderId' => $id, 'kind' => 'warranty']);
        self::assertSame($this->pragueToday()->modify('+24 months')->format('Y-m-d'), $warranty['windowEnd']);
        // an order-level claim names no goods
        self::assertSame([null, null, null, null], [$warranty['itemId'], $warranty['productName'], $warranty['variantLabel'], $warranty['sku']]);
        $this->assertClaimShape($return);
    }

    public function testOnTimeTellsAnOpenedLateClaim(): void
    {
        [$id, $itemId] = $this->completedOrder();
        $claim = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);
        self::assertTrue($claim['onTime']);

        // opened on the last day of the window is still on time
        $this->db()->executeStatement('UPDATE return_claim SET opened_at = window_end WHERE id = :id', ['id' => $claim['id']]);
        self::assertTrue($this->claimById($claim['id'])['onTime']);

        $this->db()->executeStatement("UPDATE return_claim SET opened_at = window_end + INTERVAL '1 day' WHERE id = :id", ['id' => $claim['id']]);
        self::assertFalse($this->claimById($claim['id'])['onTime']);
    }

    public function testLifecycleToResolvedRefundWithTheLedgerCheck(): void
    {
        [$id, $itemId] = $this->completedOrder(30000);
        $claim = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return', 'note' => 'Changed my mind']);

        $claim = $this->claimAction($claim['id'], 'wait', ['note' => 'Waiting for the parcel']);
        self::assertSame('waiting', $claim['status']);

        $claim = $this->claimAction($claim['id'], 'accept', ['refundAmountMinor' => 30000]);
        self::assertSame(['accepted', 30000, $this->pragueToday()->modify('+14 days')->format('Y-m-d')], [$claim['status'], $claim['refundAmountMinor'], $claim['dueAt']]);

        // no refund in the ledger since the claim was opened
        $problem = $this->claimAction($claim['id'], 'resolve', ['resolution' => 'refund'], 409);
        self::assertSame('refund_not_recorded', $problem['code']);

        // a partial refund is not enough; settling for less means accepting again with the lower amount
        $this->pay($id, 10000, 'refund');
        $problem = $this->claimAction($claim['id'], 'resolve', ['resolution' => 'refund'], 409);
        self::assertSame('refund_not_recorded', $problem['code']);
        $claim = $this->claimAction($claim['id'], 'accept', ['refundAmountMinor' => 10000]);
        $claim = $this->claimAction($claim['id'], 'resolve', ['resolution' => 'refund', 'note' => 'Paid by transfer']);
        self::assertSame(['resolved', 'refund', 10000], [$claim['status'], $claim['resolution'], $claim['refundAmountMinor']]);
        self::assertNotNull($claim['resolvedAt']);

        $order = $this->getJson('/api/admin/orders/'.$id);
        $claimEvents = array_values(array_map(static fn (array $event): string => $event['type'], array_filter($order['events'], static fn (array $event): bool => str_starts_with($event['type'], 'claim_'))));
        self::assertSame(['claim_opened', 'claim_waiting', 'claim_accepted', 'claim_accepted', 'claim_resolved'], $claimEvents, 'the re-accept that lowered the amount is journaled too');
        $this->assertClaimShape($claim);
    }

    public function testRefundsBeforeTheClaimAndVoidedOnesDoNotCoverIt(): void
    {
        [$id, $itemId] = $this->completedOrder();

        // a refund recorded before the claim belongs to the order, not to the case
        $this->pay($id, 5000, 'refund');
        $claim = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);
        $claim = $this->claimAction($claim['id'], 'accept', ['refundAmountMinor' => 5000]);
        $problem = $this->claimAction($claim['id'], 'resolve', ['resolution' => 'refund'], 409);
        self::assertSame(['refund_not_recorded', 0], [$problem['code'], $problem['coveredMinor']]);

        // a refund recorded since the opening covers it
        $this->pay($id, 5000, 'refund');
        $claim = $this->claimAction($claim['id'], 'resolve', ['resolution' => 'refund']);
        self::assertSame('resolved', $claim['status']);

        // voiding a refund recorded since the opening takes the coverage away again
        $claim2 = $this->claim(['orderId' => $id, 'kind' => 'warranty']);
        $this->claimAction($claim2['id'], 'accept', ['refundAmountMinor' => 20000]);
        $payment = $this->pay($id, 20000, 'refund');
        $this->act($id, 'payments/'.$payment['payment']['id'].'/void', ['reason' => 'Mistake']);
        $problem = $this->claimAction($claim2['id'], 'resolve', ['resolution' => 'refund'], 409);
        self::assertSame(['refund_not_recorded', 0], [$problem['code'], $problem['coveredMinor']]);
    }

    public function testRejectFromEveryOpenStageAndTerminality(): void
    {
        [$id, $itemId] = $this->completedOrder();

        $first = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'warranty', 'note' => 'Broken after two rides']);
        $first = $this->claimAction($first['id'], 'reject', ['reason' => 'Crash damage is not covered']);
        self::assertSame(['rejected', 'Crash damage is not covered'], [$first['status'], $first['resolutionNote']]);
        $this->claimAction($first['id'], 'wait', status: 409);
        $this->claimAction($first['id'], 'resolve', ['resolution' => 'repair'], 409);
        $this->claimAction($first['id'], 'reject', ['reason' => 'Twice'], 409);

        $second = $this->claim(['orderId' => $id, 'kind' => 'return']);
        $second = $this->claimAction($second['id'], 'wait', ['note' => 'Parcel on its way']);
        $second = $this->claimAction($second['id'], 'reject', ['reason' => 'No parcel, closed']);
        self::assertSame('rejected', $second['status']);

        $third = $this->claim(['orderId' => $id, 'kind' => 'warranty']);
        $this->claimAction($third['id'], 'accept', ['refundAmountMinor' => 1]);
        $third = $this->claimAction($third['id'], 'reject', ['reason' => 'Rejected after acceptance']);
        self::assertSame('rejected', $third['status']);
    }

    public function testOneOpenClaimPerLineAndNewOneAfterItCloses(): void
    {
        [$id, $itemId] = $this->completedOrder();

        $first = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);
        $problem = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'warranty'], 409);
        self::assertSame('claim_open', $problem['code']);

        // order-level claims are not limited: the line keeps its one open claim
        $this->claim(['orderId' => $id, 'kind' => 'warranty']);

        // after the line claim closes a new one is fine
        $this->claimAction($first['id'], 'reject', ['reason' => 'Closed']);
        $second = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);
        self::assertSame('open', $second['status']);
    }

    public function testForeignLineAndUnknownInputs(): void
    {
        [$id, $itemId] = $this->completedOrder();
        $held = $this->held('T-RC-OTHER', 20000, 1);
        $receipt = $this->checkout([$held => 1]);
        self::assertResponseStatusCodeSame(201);
        $otherId = $this->orderId($receipt['reference']);

        // the line belongs to another order
        $this->claim(['orderId' => $otherId, 'itemId' => $itemId, 'kind' => 'return'], 404);
        $this->claim(['orderId' => $otherId, 'itemId' => $itemId, 'kind' => 'complaint'], 422);

        $this->getJson('/api/admin/claims/01890000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
        // an id that is not a UUID is not found either
        $this->getJson('/api/admin/claims/not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        // a claim of another order is not found through this one either
        $claim = $this->claim(['orderId' => $id, 'kind' => 'return']);
        $held2 = $this->held('T-RC-THIRD', 10000, 1);
        $receipt2 = $this->checkout([$held2 => 1]);
        self::assertResponseStatusCodeSame(201);
        $thirdId = $this->orderId($receipt2['reference']);
        $this->claimAction($claim['id'], 'accept', ['refundAmountMinor' => -1], 422);
        self::assertSame($id, $this->claimById($claim['id'])['orderId']);
        self::assertNotSame($thirdId, $this->claimById($claim['id'])['orderId']);
    }

    public function testListFiltersAndSearch(): void
    {
        [$id, $itemId] = $this->completedOrder();
        $return = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);
        $warranty = $this->claim(['orderId' => $id, 'kind' => 'warranty']);
        $this->claimAction($warranty['id'], 'accept', ['refundAmountMinor' => 500]);
        $warranty = $this->claimAction($warranty['id'], 'resolve', ['resolution' => 'replacement']);

        $all = $this->getJson('/api/admin/claims');
        self::assertSame(['items', 'page', 'pages', 'total'], array_keys($all));
        self::assertSame(2, $all['total']);
        self::assertSame($warranty['id'], $all['items'][0]['id'], 'newest first');

        self::assertSame([$return['id']], array_column($this->getJson('/api/admin/claims', ['kind' => 'return'])['items'], 'id'));
        self::assertSame([$warranty['id']], array_column($this->getJson('/api/admin/claims', ['status' => 'resolved'])['items'], 'id'));
        self::assertSame(0, $this->getJson('/api/admin/claims', ['status' => 'waiting'])['total']);
        self::assertSame([$return['id']], array_column($this->getJson('/api/admin/claims', ['q' => $return['number']])['items'], 'id'));
        $reference = $this->getJson('/api/admin/orders/'.$id)['reference'];
        self::assertSame(2, $this->getJson('/api/admin/claims', ['q' => $reference])['total'], 'search by order reference');
        self::assertSame(2, $this->getJson('/api/admin/claims', ['q' => 'rider@example.test'])['total'], 'search by customer email');
    }

    public function testDashboardCountsOpenAndOverdueClaims(): void
    {
        [$id, $itemId] = $this->completedOrder();
        self::assertSame(['open' => 0, 'overdue' => 0], $this->getJson('/api/admin/dashboard')['claims']);

        $claim = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);
        self::assertSame(['open' => 1, 'overdue' => 0], $this->getJson('/api/admin/dashboard')['claims']);

        $this->claimAction($claim['id'], 'accept', ['refundAmountMinor' => 1000]);
        self::assertSame(['open' => 1, 'overdue' => 0], $this->getJson('/api/admin/dashboard')['claims']);

        $this->db()->executeStatement('UPDATE return_claim SET due_at = CURRENT_DATE - 1 WHERE id = :id', ['id' => $claim['id']]);
        self::assertSame(['open' => 1, 'overdue' => 1], $this->getJson('/api/admin/dashboard')['claims']);
    }

    public function testClaimsLiveInTheOrderAndNeverInPublicResponses(): void
    {
        [$id, $itemId] = $this->completedOrder();
        $claim = $this->claim(['orderId' => $id, 'itemId' => $itemId, 'kind' => 'return']);

        $order = $this->getJson('/api/admin/orders/'.$id);
        self::assertSame([$claim['id']], array_column($order['claims'], 'id'));

        $this->client->request('GET', '/api/orders/'.$order['reference'].'?token='.$order['lookupToken'], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('claims', $this->decode());
    }

    private function assertClaimShape(array $claim): void
    {
        $schema = Yaml::parseFile(__DIR__.'/../../config/api_doc/shop.yaml')['components']['schemas']['AdminClaim'];
        self::assertEqualsCanonicalizing($schema['required'], array_keys($claim), 'AdminClaim shape');
    }
}
