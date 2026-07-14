<?php

declare(strict_types=1);

namespace App\Tests\Checkout;

use App\Checkout\BasketLoader;
use App\Checkout\CheckoutRequest;
use App\Checkout\CheckoutService;
use App\Checkout\QuoteBuilder;
use App\Order\OrderJournal;
use App\Order\OrderLoader;
use App\Order\OrderPresenter;
use App\Order\OrderProblem;
use App\Order\OrderRules;
use App\Order\PaymentSettings;
use App\Order\StockKeeper;
use App\Pricing\SourcingCalculator;
use App\Tests\Support\OrderDefaults;
use App\Tests\Support\PricingDefaults;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CzechDeliveryTest extends TestCase
{
    public function testOtherCountriesAreRejectedBeforeInventoryChanges(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method(self::anything());
        $em = $this->createStub(EntityManagerInterface::class);
        $loader = new OrderLoader($em, $db);
        $checkout = new CheckoutService(
            $em, $db,
            new BasketLoader($db, PricingDefaults::availability($db), new SourcingCalculator(PricingDefaults::settings())),
            new QuoteBuilder(OrderDefaults::rules(), OrderDefaults::planner()), OrderDefaults::rules(),
            new OrderJournal($em), new StockKeeper($em, $db), $loader,
            new OrderPresenter($em, $loader, new OrderRules(), OrderDefaults::settings(), new PaymentSettings(['cash', 'bank_transfer'], null), $db, new MockClock()),
            'draft-2026-09',
        );
        $request = new CheckoutRequest('en', [
            'name' => 'Demo Rider', 'email' => 'rider@example.test', 'phone' => '+49 30 1234567', 'contactChannel' => 'phone',
            'country' => 'DE', 'address' => 'Demo 12', 'city' => 'Berlin', 'postalCode' => '10115',
        ], [['variantId' => '01890000-0000-7000-8000-000000000000', 'quantity' => 1]], ['method' => 'carrier_cz', 'fulfilment' => 'together'], ['privacy' => true], 0);

        try {
            $checkout->place($request, str_repeat('a', 20));
            self::fail('A German address was accepted');
        } catch (OrderProblem $problem) {
            self::assertSame([422, 'invalid_field', 'Delivery is available only within Czechia'], [$problem->status, $problem->errorCode, $problem->getMessage()]);
            self::assertSame('customer.country', $problem->extra['violations'][0]['field']);
        }
    }
}
