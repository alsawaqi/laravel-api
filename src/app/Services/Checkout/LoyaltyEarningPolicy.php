<?php

namespace App\Services\Checkout;

final class LoyaltyEarningPolicy
{
    /**
     * Paid-at-checkout methods may earn immediately. Every method that still
     * requires money to be collected or verified must wait for settlement.
     */
    public static function deferUntilPaymentConfirmed(
        ?string $method,
        string|int|float $amountDue,
    ): bool {
        $method = strtolower(trim((string) $method));
        $amountUnits = max(0, (int) round(((float) $amountDue) * 1000));

        return $amountUnits > 0
            && in_array($method, ['card', 'cod', 'transfer'], true);
    }
}
