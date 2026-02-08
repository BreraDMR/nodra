<?php

declare(strict_types=1);

namespace App\Tests\Checkout;

use App\Checkout\CheckoutRequest;
use App\Checkout\CheckoutService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CzechDeliveryTest extends TestCase
{
    public function testOtherCountriesAreRejectedBeforeInventoryChanges(): void
    {
        $checkout = new CheckoutService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
        );
        $request = new CheckoutRequest('en', [
            'name' => 'Demo Rider',
            'email' => 'rider@example.test',
            'country' => 'DE',
            'address' => 'Demo 12',
            'postalCode' => '10115',
            'district' => 'Berlin',
        ], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Delivery is available only within Czechia');
        $checkout->place($request, str_repeat('a', 20));
    }
}
