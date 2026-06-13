<?php

declare(strict_types=1);

namespace App\Tests\Delivery;

use App\Delivery\DeliveryRules;
use App\Delivery\PostalCode;
use App\Tests\Support\OrderDefaults;
use PHPUnit\Framework\TestCase;

final class DeliveryRulesTest extends TestCase
{
    public function testPragueIsPostalCodes100To199(): void
    {
        $rules = OrderDefaults::rules();
        $reasons = [];
        foreach (['100 00', '11000', '199 99', ' 150 00 ', '200 00', '099 99', '09999', '1100', 'abcde', '', null] as $code) {
            $reasons[var_export($code, true)] = $rules->unavailableReason('prague_personal', $code);
        }

        self::assertSame([
            "'100 00'" => null, "'11000'" => null, "'199 99'" => null, "' 150 00 '" => null,
            "'200 00'" => DeliveryRules::OUTSIDE_PRAGUE, "'099 99'" => DeliveryRules::OUTSIDE_PRAGUE, "'09999'" => DeliveryRules::OUTSIDE_PRAGUE,
            "'1100'" => DeliveryRules::INVALID_POSTAL_CODE, "'abcde'" => DeliveryRules::INVALID_POSTAL_CODE,
            "''" => DeliveryRules::POSTAL_CODE_REQUIRED, 'NULL' => DeliveryRules::POSTAL_CODE_REQUIRED,
        ], $reasons);
        self::assertSame('110 00', PostalCode::normalize('11000'));
        // pickup doesn't care where the customer lives
        self::assertNull($rules->unavailableReason('pickup_andel', '602 00'));
    }

    public function testPragueFeeIs149BelowAndFreeFromExactly500(): void
    {
        $rules = OrderDefaults::rules();

        self::assertSame(14900, $rules->fee('prague_personal', 49999));
        self::assertSame(0, $rules->fee('prague_personal', 50000));
        self::assertSame(0, $rules->fee('prague_personal', 50001));
        self::assertSame(0, $rules->fee('pickup_andel', 100));
        self::assertSame('Místo a čas předání na Andělu domluvíme zprávou.', $rules->note('pickup_andel', 'cs'));
        self::assertSame('We agree the place and time at Anděl by message.', $rules->note('pickup_andel', 'en'));
        self::assertNull($rules->note('prague_personal', 'cs'));
        self::assertSame([50000, null, null], [$rules->freeFrom('prague_personal'), $rules->freeFrom('pickup_andel'), $rules->freeFrom('carrier_cz')]);
    }

    public function testCarrierIsOffWhileItsFeeIsNull(): void
    {
        $off = OrderDefaults::rules();
        self::assertSame(['pickup_andel', 'prague_personal'], $off->offered());
        self::assertSame(DeliveryRules::NOT_OFFERED, $off->unavailableReason('carrier_cz', '602 00'));
        try {
            $off->fee('carrier_cz', 10000);
            self::fail('A carrier fee was computed without a tariff');
        } catch (\DomainException) {
        }

        $on = OrderDefaults::rules(carrierFeeMinor: 9900);
        self::assertSame(['pickup_andel', 'prague_personal', 'carrier_cz'], $on->offered());
        self::assertNull($on->unavailableReason('carrier_cz', '602 00'));
        self::assertSame(9900, $on->fee('carrier_cz', 90000));
    }
}
