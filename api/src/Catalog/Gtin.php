<?php

declare(strict_types=1);

namespace App\Catalog;

final class Gtin
{
    /** Returns the digits of a valid EAN/UPC/GTIN-14, or throws. */
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/[\s-]/', '', $value);
        if (!preg_match('/^(\d{8}|\d{12,14})$/', $digits)) {
            throw new \InvalidArgumentException('EAN must have 8, 12, 13 or 14 digits');
        }
        $sum = 0;
        $body = substr($digits, 0, -1);
        // weights go 3,1,3,1… from the digit next to the check digit
        foreach (array_reverse(str_split($body)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }
        if ((10 - $sum % 10) % 10 !== (int) substr($digits, -1)) {
            throw new \InvalidArgumentException('EAN check digit does not match');
        }

        return $digits;
    }
}
