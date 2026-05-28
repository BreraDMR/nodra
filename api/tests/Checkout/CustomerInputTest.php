<?php

declare(strict_types=1);

namespace App\Tests\Checkout;

use App\Checkout\CustomerInput;
use App\Order\OrderProblem;
use PHPUnit\Framework\TestCase;

final class CustomerInputTest extends TestCase
{
    private const CUSTOMER = ['name' => ' Rider ', 'email' => 'rider@example.test', 'phone' => '777 123 456', 'contactChannel' => 'telegram',
        'address' => 'Vinohradská 1', 'city' => 'Praha', 'postalCode' => '12000', 'district' => 'Praha 2', 'deliveryNote' => 'Ring twice'];

    public function testPhoneNeedsAPlusAnd9To15DigitsOrNineCzechDigits(): void
    {
        self::assertSame('+420777123456', CustomerInput::phone('777 123 456'));
        self::assertSame('+420777123456', CustomerInput::phone('+420 777 123 456'));
        self::assertSame('+491701234567', CustomerInput::phone('+49 170 1234567'));
        self::assertSame('+123456789', CustomerInput::phone('+123456789'));
        foreach (['77712345', '+12345678', '+1234567890123456', '777-123-456', '00420777123456', '', null, 777123456] as $bad) {
            try {
                CustomerInput::phone($bad);
                self::fail(var_export($bad, true).' was accepted');
            } catch (OrderProblem $problem) {
                self::assertSame('customer.phone', $problem->extra['violations'][0]['field']);
            }
        }
    }

    public function testDeliveryNeedsTheAddressAndPickupIgnoresIt(): void
    {
        self::assertSame([
            'name' => 'Rider', 'email' => 'rider@example.test', 'phone' => '+420777123456', 'contactChannel' => 'telegram',
            'address' => 'Vinohradská 1', 'city' => 'Praha', 'postalCode' => '120 00', 'district' => 'Praha 2', 'deliveryNote' => 'Ring twice',
        ], CustomerInput::normalize(self::CUSTOMER, 'prague_personal'));

        $pickup = CustomerInput::normalize(['address' => '', 'city' => null, 'postalCode' => 'nonsense'] + self::CUSTOMER, 'pickup_andel');
        self::assertSame(['', '', '', ''], [$pickup['address'], $pickup['city'], $pickup['postalCode'], $pickup['district']]);

        foreach (['address', 'city', 'postalCode'] as $field) {
            try {
                CustomerInput::normalize([$field => ' '] + self::CUSTOMER, 'prague_personal');
                self::fail($field.' was not required');
            } catch (OrderProblem $problem) {
                self::assertSame('customer.'.$field, $problem->extra['violations'][0]['field']);
            }
        }
    }

    public function testContactChannelAndEmailAreChecked(): void
    {
        foreach ([['contactChannel' => 'sms'], ['contactChannel' => null], ['email' => 'not-an-email'], ['deliveryNote' => str_repeat('x', 501)]] as $override) {
            try {
                CustomerInput::normalize($override + self::CUSTOMER, 'pickup_andel');
                self::fail(json_encode($override).' was accepted');
            } catch (OrderProblem $problem) {
                self::assertSame('customer.'.array_key_first($override), $problem->extra['violations'][0]['field']);
            }
        }
    }
}
