<?php

namespace App\Services\Checkout;

interface PaymentGateway
{
    /**
     * @param array<string, mixed> $paymentPayload
     * @param array<string, mixed> $context
     */
    public function createIntent(
        ?string $method,
        string|int|float $amount,
        string $currency,
        array $paymentPayload = [],
        array $context = [],
    ): PaymentIntent;
}
