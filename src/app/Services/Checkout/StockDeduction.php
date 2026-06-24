<?php

namespace App\Services\Checkout;

use InvalidArgumentException;

final class StockDeduction
{
    public static function remainingAfterDeduction(int $availableQuantity, int $requestedQuantity): int
    {
        if ($requestedQuantity <= 0) {
            throw new InvalidArgumentException('Requested quantity must be greater than zero.');
        }

        if ($availableQuantity < $requestedQuantity) {
            throw new InvalidArgumentException('Insufficient stock for this product.');
        }

        return $availableQuantity - $requestedQuantity;
    }
}
