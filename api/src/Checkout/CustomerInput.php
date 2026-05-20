<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Delivery\DeliveryMethod;
use App\Delivery\PostalCode;
use App\Order\OrderProblem;

/** Checks and normalizes the customer block of a checkout request. Every error names its field. */
final class CustomerInput
{
    public const CHANNELS = ['whatsapp', 'telegram', 'phone'];

    /**
     * Street address, city and postal code are needed for delivery and ignored for pickup.
     *
     * @return array{name: string, email: string, phone: string, contactChannel: string, address: string, city: string, postalCode: string, district: string, deliveryNote: ?string}
     */
    public static function normalize(array $input, string $method): array
    {
        $name = self::text($input, 'name', 160, true);
        $email = self::text($input, 'email', 180, true);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw OrderProblem::invalid('customer.email', 'A valid email is required');
        }
        $phone = self::phone($input['phone'] ?? null);
        $channel = $input['contactChannel'] ?? null;
        if (!in_array($channel, self::CHANNELS, true)) {
            throw OrderProblem::invalid('customer.contactChannel', 'Choose how to contact you: whatsapp, telegram or phone');
        }
        $country = strtoupper(self::text($input, 'country', 2, false) ?: 'CZ');
        if ($country !== 'CZ') {
            throw OrderProblem::invalid('customer.country', 'Delivery is available only within Czechia');
        }
        $note = self::text($input, 'deliveryNote', 500, false);
        $address = ['address' => '', 'city' => '', 'postalCode' => '', 'district' => ''];
        if ($method !== DeliveryMethod::PICKUP_ANDEL) {
            $address = [
                'address' => self::text($input, 'address', 255, true),
                'city' => self::text($input, 'city', 120, true),
                'postalCode' => PostalCode::normalize(self::text($input, 'postalCode', 24, true))
                    ?? throw OrderProblem::invalid('customer.postalCode', 'A Czech postal code is required, like 110 00'),
                'district' => self::text($input, 'district', 120, false),
            ];
        }

        return ['name' => $name, 'email' => $email, 'phone' => $phone, 'contactChannel' => $channel] + $address + ['deliveryNote' => $note === '' ? null : $note];
    }

    /** "+" and 9 to 15 digits once spaces are gone; a bare 9-digit number is Czech. */
    public static function phone(mixed $value): string
    {
        $compact = is_string($value) ? preg_replace('/\s+/u', '', $value) : '';
        if (preg_match('/^\d{9}$/', $compact)) {
            return '+420'.$compact;
        }
        if (!preg_match('/^\+\d{9,15}$/', $compact)) {
            throw OrderProblem::invalid('customer.phone', 'A phone number needs + and 9 to 15 digits, or 9 digits for a Czech number');
        }

        return $compact;
    }

    private static function text(array $input, string $field, int $max, bool $required): string
    {
        $value = $input[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw OrderProblem::invalid('customer.'.$field, sprintf('Customer %s must be text', $field));
        }
        $value = trim((string) $value);
        if ($required && $value === '') {
            throw OrderProblem::invalid('customer.'.$field, sprintf('Customer %s is required', $field));
        }
        if (mb_strlen($value) > $max) {
            throw OrderProblem::invalid('customer.'.$field, sprintf('Customer %s can have at most %d characters', $field, $max));
        }

        return $value;
    }
}
