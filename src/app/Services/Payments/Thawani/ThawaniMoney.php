<?php

declare(strict_types=1);

namespace App\Services\Payments\Thawani;

use InvalidArgumentException;

/**
 * Exact OMR-to-baisa conversion without binary floating-point arithmetic.
 */
final class ThawaniMoney
{
    public static function omrToBaisa(string|int $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(?<whole>\d+)(?:\.(?<fraction>\d{1,3}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('The OMR amount must be a non-negative decimal with at most three decimal places.');
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches['fraction'] ?? '', 3, '0');
        $maxWhole = (string) intdiv(PHP_INT_MAX, 1000);

        if (strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            throw new InvalidArgumentException('The OMR amount is too large.');
        }

        $baisa = ((int) $whole * 1000) + (int) $fraction;

        if ($baisa < 0) {
            throw new InvalidArgumentException('The OMR amount is too large.');
        }

        return $baisa;
    }
}
