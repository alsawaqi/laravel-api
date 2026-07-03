<?php

namespace App\Support\Vendors;

/**
 * Pure per-product commission math for vendor order lines.
 *
 * Mirrors the style of isc-admin-api's VendorPayoutRules: static, no DB,
 * no framework dependencies, money rounded to 3 decimal places (OMR).
 *
 * Commission semantics:
 * - type 'percent': value must be > 0 and <= 100; line commission =
 *   round(lineSubtotal * value / 100, 3).
 * - type 'fixed': value must be > 0 (per-UNIT amount in OMR); line
 *   commission = round(value * quantity, 3).
 * - Anything else (missing/unknown type, non-numeric value, out-of-range
 *   value) is treated as "no commission set" => null.
 */
class VendorCommissionCalculator
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    /**
     * Commission for a single order line, or null when the product has no
     * valid commission configured (invalid == missing).
     */
    public static function lineCommission(?string $type, mixed $value, float $lineSubtotal, int $quantity): ?float
    {
        $normalizedType = self::normalizeType($type);

        if ($normalizedType === null || !is_numeric($value)) {
            return null;
        }

        $numericValue = (float) $value;

        if ($numericValue <= 0) {
            return null;
        }

        if ($normalizedType === self::TYPE_PERCENT) {
            if ($numericValue > 100) {
                return null;
            }

            return round($lineSubtotal * $numericValue / 100, 3);
        }

        // fixed: per-unit amount
        return round($numericValue * $quantity, 3);
    }

    /**
     * Plan the commission rollup for one vendor's group of order lines.
     *
     * Each input line: ['subtotal' => float, 'quantity' => int,
     * 'commission_type' => ?string, 'commission_value' => mixed].
     *
     * covered is true only when there is at least one line AND every line
     * has a valid commission. total sums the valid per-line amounts
     * (rounded to 3dp); per-line amounts are returned in 'lines' in input
     * order (null for lines without a valid commission).
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array{covered: bool, total: float, lines: array<int, ?float>}
     */
    public static function planForVendorLines(array $lines): array
    {
        $covered = $lines !== [];
        $total = 0.0;
        $perLine = [];

        foreach ($lines as $line) {
            $amount = self::lineCommission(
                isset($line['commission_type']) ? (string) $line['commission_type'] : null,
                $line['commission_value'] ?? null,
                (float) ($line['subtotal'] ?? 0),
                (int) ($line['quantity'] ?? 0),
            );

            $perLine[] = $amount;

            if ($amount === null) {
                $covered = false;
                continue;
            }

            $total += $amount;
        }

        return [
            'covered' => $covered,
            'total' => round($total, 3),
            'lines' => $perLine,
        ];
    }

    private static function normalizeType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalized = strtolower(trim($type));

        return in_array($normalized, [self::TYPE_PERCENT, self::TYPE_FIXED], true)
            ? $normalized
            : null;
    }
}
