<?php

declare(strict_types=1);

namespace App\Delivery;

final class PostalCode
{
    /** "11000" or "110 00" => "110 00"; null when it isn't a Czech postal code. */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\s+/u', '', trim((string) $value));

        return preg_match('/^\d{5}$/', $digits) ? substr($digits, 0, 3).' '.substr($digits, 3) : null;
    }

    /** Prague is 100 00 to 199 99. Takes a normalized code. */
    public static function isPrague(string $normalized): bool
    {
        return str_starts_with($normalized, '1');
    }
}
