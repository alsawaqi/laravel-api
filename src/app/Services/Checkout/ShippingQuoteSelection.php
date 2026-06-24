<?php

namespace App\Services\Checkout;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class ShippingQuoteSelection
{
    public static function assertNotExpired(mixed $expiresAt, CarbonInterface|string|null $now = null): void
    {
        if (!$expiresAt) {
            return;
        }

        $clock = $now instanceof CarbonInterface ? $now : Carbon::parse($now ?: now());

        if (Carbon::parse($expiresAt)->lessThanOrEqualTo($clock)) {
            throw new InvalidArgumentException('Shipping quote expired. Please refresh shipping options.');
        }
    }

    /**
     * @param list<array<string, mixed>> $options
     * @param array<string, mixed> $selected
     *
     * @return array<string, mixed>
     */
    public static function match(array $options, array $selected, float $priceTolerance = 0.001): array
    {
        foreach ($options as $option) {
            if ((int) ($option['shipper_id'] ?? 0) !== (int) ($selected['shipper_id'] ?? 0)) {
                continue;
            }

            if ((int) ($option['destination_id'] ?? 0) !== (int) ($selected['destination_id'] ?? 0)) {
                continue;
            }

            if ((string) ($option['basis'] ?? '') !== (string) ($selected['basis'] ?? '')) {
                continue;
            }

            $selectedPrice = (float) ($selected['price'] ?? $selected['total_price'] ?? 0);
            $currentPrice = (float) ($option['total_price'] ?? $option['price'] ?? 0);

            if (abs($currentPrice - $selectedPrice) <= $priceTolerance) {
                return $option;
            }
        }

        throw new InvalidArgumentException('Selected shipping quote is no longer available. Please refresh shipping options.');
    }
}
