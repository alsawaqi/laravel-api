<?php

namespace App\Services\Checkout;

use InvalidArgumentException;

final class LoyaltyRedemptionCalculator
{
    /**
     * @return array{points: int, amount: string}
     */
    public static function calculate(
        int $requestedPoints,
        int $availablePoints,
        string|int|float $orderTotal,
        string|int|float $redeemRulePoints,
        string|int|float $redeemRuleAmount,
    ): array {
        if ($requestedPoints <= 0 || $availablePoints <= 0) {
            return ['points' => 0, 'amount' => '0.000'];
        }

        $rulePoints = (float) $redeemRulePoints;
        $ruleAmountUnits = self::moneyToUnits($redeemRuleAmount);
        $orderTotalUnits = self::moneyToUnits($orderTotal);

        if ($rulePoints <= 0 || $ruleAmountUnits <= 0) {
            throw new InvalidArgumentException('Loyalty redemption is not configured.');
        }

        $valuePerPointUnits = $ruleAmountUnits / $rulePoints;

        if ($valuePerPointUnits <= 0) {
            throw new InvalidArgumentException('Loyalty redemption is not configured.');
        }

        $maxPointsByTotal = (int) floor($orderTotalUnits / $valuePerPointUnits);
        $points = min($requestedPoints, $availablePoints, $maxPointsByTotal);
        $amountUnits = min((int) round($points * $valuePerPointUnits), $orderTotalUnits);

        return [
            'points' => $points,
            'amount' => self::unitsToMoney($amountUnits),
        ];
    }

    private static function moneyToUnits(string|int|float $amount): int
    {
        return max(0, (int) round(((float) $amount) * 1000));
    }

    private static function unitsToMoney(int $units): string
    {
        return number_format($units / 1000, 3, '.', '');
    }
}
