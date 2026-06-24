<?php

namespace App\Services\Checkout;

final class PaymentStatus
{
    public const UNPAID = 'unpaid';
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::UNPAID,
            self::PENDING,
            self::PAID,
            self::FAILED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED,
        ];
    }

    public static function initialFor(?string $method, string|int|float $amountDue): string
    {
        $amount = self::moneyToUnits($amountDue);
        $method = strtolower(trim((string) $method));

        if ($amount === 0) {
            return self::PAID;
        }

        if ($method === '') {
            return self::UNPAID;
        }

        return match ($method) {
            'card', 'cod', 'transfer' => self::PENDING,
            'loyalty' => self::PAID,
            default => self::UNPAID,
        };
    }

    private static function moneyToUnits(string|int|float $amount): int
    {
        return max(0, (int) round(((float) $amount) * 1000));
    }
}
