<?php

namespace App\Support\Pricing;

/**
 * Pure quantity-tier resolver for bulk pricing (no DB, no framework state).
 *
 * A tier is {Min_Qty int >= 1, Max_Qty int|null (null = open-ended "and
 * above"), Unit_Price decimal > 0}. Tier sets are validated at write time
 * (isc-admin-api / isc-multivendor-api) to be non-overlapping, so at most one
 * tier can match a quantity; this resolver simply finds it.
 *
 * Accepts tiers as arrays or objects (Eloquent models included) with either
 * house-style keys (Min_Qty/Max_Qty/Unit_Price) or snake_case
 * (min_qty/max_qty/unit_price).
 */
class BulkPriceResolver
{
    /**
     * The tier whose [Min_Qty, Max_Qty ?? INF] range contains $qty,
     * normalized to ['min_qty' => int, 'max_qty' => ?int, 'unit_price' => float].
     * Null when no tier covers the quantity (gap / below all tiers / empty set).
     */
    public static function tierFor(iterable $tiers, int $qty): ?array
    {
        if ($qty < 1) {
            return null;
        }

        foreach ($tiers as $tier) {
            $normalized = self::normalize($tier);

            if ($normalized === null) {
                continue;
            }

            $withinMin = $qty >= $normalized['min_qty'];
            $withinMax = $normalized['max_qty'] === null || $qty <= $normalized['max_qty'];

            if ($withinMin && $withinMax) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Tier unit price for the quantity, or null when no tier applies
     * (caller falls back to the normal — possibly discounted — price).
     */
    public static function unitPriceFor(iterable $tiers, int $qty): ?float
    {
        $tier = self::tierFor($tiers, $qty);

        return $tier !== null ? $tier['unit_price'] : null;
    }

    /**
     * Normalize a tier row (array or object, either key style) to
     * ['min_qty', 'max_qty', 'unit_price']. Null for malformed/unsellable
     * rows (missing min or price, or price <= 0) — defensive only; validated
     * sets never contain these.
     */
    private static function normalize(mixed $tier): ?array
    {
        $min = self::value($tier, ['Min_Qty', 'min_qty']);
        $max = self::value($tier, ['Max_Qty', 'max_qty']);
        $price = self::value($tier, ['Unit_Price', 'unit_price']);

        if ($min === null || !is_numeric($min) || $price === null || !is_numeric($price)) {
            return null;
        }

        $price = round((float) $price, 3);

        if ($price <= 0) {
            return null;
        }

        return [
            'min_qty' => (int) $min,
            'max_qty' => ($max === null || $max === '') ? null : (int) $max,
            'unit_price' => $price,
        ];
    }

    private static function value(mixed $tier, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($tier) && array_key_exists($key, $tier) && $tier[$key] !== null) {
                return $tier[$key];
            }

            if (is_object($tier) && isset($tier->{$key})) {
                return $tier->{$key};
            }
        }

        return null;
    }
}
