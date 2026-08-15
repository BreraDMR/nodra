<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Installation\InstallationSettings;
use App\Order\OrderQueues;
use App\Tests\Support\AdminOrderSteps;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Evening installation bookings (D08.1–D08.2): Prague evening windows with the DST night included, the count
 * limit and the travel gap that keeps two windows apart, the preliminary vs confirmed work window split, the
 * works list that ships empty, and the whole lifecycle on one order.
 */
final class InstallationBookingTest extends ApiTestCase
{
    use AdminOrderSteps;

    private const PRAGUE = OrderQueues::TIMEZONE;

    /** A Prague evening window days ahead, as [fromAtom, toAtom, day]. */
    private function evening(int $daysAhead, int $fromHour = 17, int $fromMinute = 0, int $toHour = 19, int $toMinute = 0): array
    {
        $day = (new \DateTimeImmutable('now', new \DateTimeZone(self::PRAGUE)))->modify("+{$daysAhead} days")->format('Y-m-d');
        $prague = new \DateTimeZone(self::PRAGUE);

        return [
            (new \DateTimeImmutable("{$day}T{$fromHour}:{$fromMinute}", $prague))->format(\DATE_ATOM),
            (new \DateTimeImmutable("{$day}T{$toHour}:{$toMinute}", $prague))->format(\DATE_ATOM),
            $day,
        ];
    }

    /** A placed order of one held variant; returns [orderId, itemId]. */
    private function placedOrder(string $sku, int $priceCzk = 30000): array
    {
        $held = $this->held($sku, $priceCzk, 1);
        $id = $this->placeOrder([$held => 1]);
        if ($this->token === '') { $this->token = $this->loginAdmin(); }
        $order = $this->getJson('/api/admin/orders/'.$id);
        $line = $this->line($order, $sku);

        return [$id, $line['id']];
    }

    /** @param array<string, mixed> $payload */
    private function book(array $payload, int $status = 201): array
    {
        $result = $this->sendJson('POST', '/api/admin/installations', $payload, $this->token);
        self::assertResponseStatusCodeSame($status, json_encode($result));

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function bookingAction(string $bookingId, string $action, array $payload = [], int $status = 200): array
    {
        $result = $this->sendJson('POST', '/api/admin/installations/'.$bookingId.'/'.$action, $payload, $this->token);
        self::assertResponseStatusCodeSame($status, $action.': '.json_encode($result));

        return $result;
    }

    public function testWindowsStayInsideOnePragueEvening(): void
    {
        [$id] = $this->placedOrder('T-IB-WINDOW');
        [$from, $to] = $this->evening(2);

        $booking = $this->book(['orderId' => $id, 'from' => $from, 'to' => $to]);
        self::assertSame(['planned', $from, $to, 120, null], [$booking['status'], $booking['from'], $booking['to'], $booking['durationMinutes'], $booking['priceMinor']]);
        $this->assertBookingShape($booking);

        // the evening runs 17:00–21:00 and stays on one Prague date
        [$earlyFrom, $earlyTo] = $this->evening(2, 16, 0, 19, 0);
        $this->book(['orderId' => $id, 'from' => $earlyFrom, 'to' => $earlyTo], 422);
        [$lateFrom, $lateTo] = $this->evening(2, 18, 0, 21, 30);
        $this->book(['orderId' => $id, 'from' => $lateFrom, 'to' => $lateTo], 422);
        [$reversedFrom, $reversedTo] = $this->evening(2, 19, 0, 18, 0);
        $this->book(['orderId' => $id, 'from' => $reversedFrom, 'to' => $reversedTo], 422);
        // ends the next Prague night: leaves the evening even though both bounds are ISO instants
        $crossing = (new \DateTimeImmutable($to))->modify('+6 hours')->format(\DATE_ATOM);
        $this->book(['orderId' => $id, 'from' => $to, 'to' => $crossing], 422);
        $this->book(['orderId' => $id, 'from' => 'not-a-time', 'to' => $to], 422);
    }

    public function testEveningCountLimitAndCancellationFreesTheSlot(): void
    {
        [$id] = $this->placedOrder('T-IB-LIMIT');
        [$firstFrom, $firstTo, $day] = $this->evening(3, 17, 0, 17, 30);
        [$secondFrom, $secondTo] = $this->evening(3, 18, 0, 18, 30);
        [$thirdFrom, $thirdTo] = $this->evening(3, 19, 0, 19, 30);

        $this->book(['orderId' => $id, 'from' => $firstFrom, 'to' => $firstTo]);
        $second = $this->book(['orderId' => $id, 'from' => $secondFrom, 'to' => $secondTo]);

        // the limit is 2 per evening: a third window on the same evening is full even though it does not overlap
        $problem = $this->book(['orderId' => $id, 'from' => $thirdFrom, 'to' => $thirdTo], 409);
        self::assertSame('evening_full', $problem['code']);

        // cancelling one booking frees its slot
        $this->bookingAction($second['id'], 'cancel', ['reason' => 'Customer called off']);
        $third = $this->book(['orderId' => $id, 'from' => $thirdFrom, 'to' => $thirdTo]);
        self::assertSame('planned', $third['status']);

        // the list can be filtered by that evening and by status; the evening keeps both, open and closed
        $page = $this->getJson('/api/admin/installations', ['date' => $day]);
        self::assertSame(3, $page['total']);
        self::assertSame(['planned', 'cancelled', 'planned'], array_column($page['items'], 'status'));
        self::assertSame(1, $this->getJson('/api/admin/installations', ['status' => 'cancelled'])['total']);
    }

    public function testOverlappingWindowsAreRefusedEvenUnderTheCountLimit(): void
    {
        [$id] = $this->placedOrder('T-IB-OVERLAP');
        [$firstFrom, $firstTo] = $this->evening(4, 17, 0, 19, 0);

        $this->book(['orderId' => $id, 'from' => $firstFrom, 'to' => $firstTo]);

        // two 17:00–19:00 windows fit the count limit and still put the installer in two places at once
        [$overlapFrom, $overlapTo] = $this->evening(4, 18, 0, 20, 0);
        $problem = $this->book(['orderId' => $id, 'from' => $overlapFrom, 'to' => $overlapTo], 409);
        self::assertSame('window_taken', $problem['code']);

        // the travel gap: 29 minutes apart is not enough, 30 is
        [$shortFrom, $shortTo] = $this->evening(4, 19, 29, 21, 0);
        $problem = $this->book(['orderId' => $id, 'from' => $shortFrom, 'to' => $shortTo], 409);
        self::assertSame('window_taken', $problem['code']);
        [$exactFrom, $exactTo] = $this->evening(4, 19, 30, 21, 0);
        $second = $this->book(['orderId' => $id, 'from' => $exactFrom, 'to' => $exactTo]);
        self::assertSame('planned', $second['status']);
    }

    public function testRescheduleOnPlannedAndConfirmedAndTerminality(): void
    {
        [$id] = $this->placedOrder('T-IB-LIFE');
        [$from, $to] = $this->evening(5, 17, 0, 19, 0);
        $booking = $this->book(['orderId' => $id, 'from' => $from, 'to' => $to, 'note' => 'Front wheel and brakes']);

        // a planned booking moves its preliminary window
        [$newFrom, $newTo] = $this->evening(6, 17, 0, 19, 0);
        $booking = $this->bookingAction($booking['id'], 'reschedule', ['from' => $newFrom, 'to' => $newTo]);
        self::assertSame([$newFrom, $newTo], [$booking['from'], $booking['to']]);

        // confirming fixes the work window, by default from the preliminary one
        $booking = $this->bookingAction($booking['id'], 'confirm', ['compatibilityNote' => 'Wheel size and brake type checked with the customer']);
        self::assertSame('confirmed', $booking['status']);
        $this->assertSameInstant($newFrom, $booking['workFrom']);
        $this->assertSameInstant($newTo, $booking['workTo']);

        // a confirmed booking moves its work window; atoms come back in UTC after a round trip, so compare instants
        [$movedFrom, $movedTo] = $this->evening(7, 17, 0, 19, 0);
        $booking = $this->bookingAction($booking['id'], 'reschedule', ['from' => $movedFrom, 'to' => $movedTo]);
        $this->assertSameInstant($movedFrom, $booking['workFrom']);
        $this->assertSameInstant($movedTo, $booking['workTo']);
        $this->assertSameInstant($newFrom, $booking['from'], 'the preliminary window stays as agreed');

        $booking = $this->bookingAction($booking['id'], 'complete', ['reason' => 'Installed, brakes bled']);
        self::assertSame(['done', 'Installed, brakes bled'], [$booking['status'], $booking['resultNote']]);
        $this->bookingAction($booking['id'], 'reschedule', ['from' => $movedFrom, 'to' => $movedTo], 409);
        $this->bookingAction($booking['id'], 'cancel', ['reason' => 'Twice'], 409);

        // cancel works from planned too and closes the booking for good
        [$from2, $to2] = $this->evening(8, 17, 0, 19, 0);
        $other = $this->book(['orderId' => $id, 'from' => $from2, 'to' => $to2]);
        $other = $this->bookingAction($other['id'], 'cancel', ['reason' => 'Parts did not arrive']);
        self::assertSame('cancelled', $other['status']);
        $this->bookingAction($other['id'], 'confirm', ['compatibilityNote' => 'Late'], 409);
    }

    public function testTheDstChangeoverNightIsStillJudgedByPragueWallTime(): void
    {
        [$id] = $this->placedOrder('T-IB-DST');
        // 2026-10-25 is the night the Czech clocks go back: 17:00 wall time is CET (+01:00) that day
        $day = '2026-10-25';
        $prague = new \DateTimeZone(self::PRAGUE);
        $from = (new \DateTimeImmutable("{$day}T17:00", $prague))->format(\DATE_ATOM);
        $to = (new \DateTimeImmutable("{$day}T21:00", $prague))->format(\DATE_ATOM);
        $booking = $this->book(['orderId' => $id, 'from' => $from, 'to' => $to]);
        self::assertSame([$from, $to, 240], [$booking['from'], $booking['to'], $booking['durationMinutes']]);

        // the same instants written with the summer offset are the same Prague wall time — and the evening
        // is shared across orders, so the window is taken
        $summerFrom = (new \DateTimeImmutable($from))->setTimezone(new \DateTimeZone('+02:00'))->format(\DATE_ATOM);
        $summerTo = (new \DateTimeImmutable($to))->setTimezone(new \DateTimeZone('+02:00'))->format(\DATE_ATOM);
        [$otherId] = $this->placedOrder('T-IB-DST2');
        $problem = $this->book(['orderId' => $otherId, 'from' => $summerFrom, 'to' => $summerTo], 409);
        self::assertSame('window_taken', $problem['code']);

        // a window that starts at 16:00 Prague wall time is refused no matter the offset it is written in
        $early = (new \DateTimeImmutable("{$day}T16:00", $prague))->format(\DATE_ATOM);
        $this->book(['orderId' => $otherId, 'from' => $early, 'to' => $to], 422);
    }

    public function testWorksStayEmptyAndUnknownCodesAreRefused(): void
    {
        [$id] = $this->placedOrder('T-IB-WORKS');
        [$from, $to] = $this->evening(9, 17, 0, 19, 0);

        // the works list ships empty (D00.4): no code is known, nothing is priced
        $booking = $this->book(['orderId' => $id, 'from' => $from, 'to' => $to, 'works' => []]);
        self::assertSame([[], [], null], [$booking['works'], $booking['workNames'], $booking['priceMinor']]);
        $this->book(['orderId' => $id, 'from' => $from, 'to' => $to, 'works' => ['no-such-work']], 422);
    }

    public function testTheSettingsNameTheWorksAndTheEveningRules(): void
    {
        if ($this->token === '') { $this->token = $this->loginAdmin(); }
        $settings = $this->getJson('/api/admin/settings')['installation'];
        self::assertSame([[], 17, 21, 2, 30], [$settings['works'], $settings['eveningStart'], $settings['eveningEnd'], $settings['maxPerEvening'], $settings['travelMinutes']]);
    }

    public function testInstallationsLiveOnlyInTheAdmin(): void
    {
        [$id] = $this->placedOrder('T-IB-PRIVATE');
        [$from, $to] = $this->evening(10, 17, 0, 19, 0);
        $booking = $this->book(['orderId' => $id, 'from' => $from, 'to' => $to]);

        $order = $this->getJson('/api/admin/orders/'.$id);
        self::assertArrayNotHasKey('installations', $order, 'the order view keeps its shape; the registry screen shows the bookings');
        $this->getJson('/api/admin/installations/'.$booking['id']);
        self::assertResponseStatusCodeSame(200);

        $events = array_filter($order['events'], static fn (array $event): bool => str_starts_with($event['type'], 'installation_'));
        self::assertSame(['installation_booked'], array_column($events, 'type'));
        self::assertSame(['test-admin@nodra.test'], array_unique(array_column($events, 'actor')), 'the journal carries the admin');

        $this->client->request('GET', '/api/orders/'.$order['reference'].'?token='.$order['lookupToken'], server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('installations', $this->decode());
        self::assertArrayNotHasKey('installation', $this->decode());
    }

    /** The through scenario: one order from checkout to a completed installation, the ledger untouched. */
    public function testThroughScenarioFromCheckoutToDoneInstallation(): void
    {
        $held = $this->held('T-IB-SCENARIO', 40000, 1);
        $receipt = $this->checkout([$held => 1]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->orderId($receipt['reference']);
        if ($this->token === '') { $this->token = $this->loginAdmin(); }

        $order = $this->act($id, 'confirm', ['customerAgreedVia' => ['channel' => 'whatsapp', 'note' => 'Yes']]);
        $this->act($id, 'shipments/'.$order['shipments'][0]['id'].'/schedule', $this::WINDOW);
        $this->act($id, 'shipments/'.$order['shipments'][0]['id'].'/hand-over');
        $order = $this->pay($id, $order['total']['amount'])['order'];
        self::assertSame('completed', $order['status']);

        [$from, $to] = $this->evening(11, 17, 0, 19, 0);
        $booking = $this->book(['orderId' => $id, 'from' => $from, 'to' => $to, 'note' => 'Brake service at home']);
        $booking = $this->bookingAction($booking['id'], 'confirm', ['compatibilityNote' => 'Hydraulic brakes confirmed on the phone']);
        $booking = $this->bookingAction($booking['id'], 'complete', ['reason' => 'Both brakes serviced, customer happy']);
        self::assertSame(['done', $booking['completedAt'] !== null], [$booking['status'], true]);

        $after = $this->getJson('/api/admin/orders/'.$id);
        self::assertSame($order['payments'], $after['payments'], 'an installation moves no money by itself');
    }

    /** The pricing rules of the works list as pure settings behaviour: sum, unpriced works and unknown codes. */
    public function testSettingsPriceSumsPricedWorksAndStaysNullWhileAnyIsUnpriced(): void
    {
        $settings = new InstallationSettings(
            eveningStart: 17, eveningEnd: 21, maxPerEvening: 2, travelMinutes: 30,
            works: [
                ['code' => 'brakes', 'name' => 'Servis brzd', 'priceMinor' => 99000],
                ['code' => 'gears', 'name' => 'Seřízení přehazovačky', 'priceMinor' => null],
            ],
        );
        self::assertSame([99000, null, null], [
            $settings->priceMinor(['brakes']),
            $settings->priceMinor(['brakes', 'gears']),
            $settings->priceMinor(['no-such']),
        ]);
        self::assertTrue($settings->allowsCustomerPurchase(), 'a filled works list is what a customer could buy');
        $empty = new InstallationSettings(eveningStart: 17, eveningEnd: 21, maxPerEvening: 2, travelMinutes: 30, works: []);
        self::assertFalse($empty->allowsCustomerPurchase(), 'with an empty works list the customer sees no installation to buy');
        self::assertTrue($settings->work('brakes')['name'] === 'Servis brzd');
    }

    private function assertBookingShape(array $booking): void
    {
        $schema = Yaml::parseFile(__DIR__.'/../../config/api_doc/shop.yaml')['components']['schemas']['AdminInstallation'];
        self::assertEqualsCanonicalizing($schema['required'], array_keys($booking), 'AdminInstallation shape');
    }

    private function assertSameInstant(string $expected, ?string $actual, string $message = ''): void
    {
        self::assertNotNull($actual, $message);
        self::assertEquals(new \DateTimeImmutable($expected), new \DateTimeImmutable($actual), $message);
    }
}
