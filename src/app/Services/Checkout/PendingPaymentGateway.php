<?php

namespace App\Services\Checkout;

final class PendingPaymentGateway implements PaymentGateway
{
    public function createIntent(
        ?string $method,
        string|int|float $amount,
        string $currency,
        array $paymentPayload = [],
        array $context = [],
    ): PaymentIntent {
        $method = strtolower(trim((string) ($method ?: $paymentPayload['method'] ?? '')));

        return new PaymentIntent(
            method: $method ?: 'unselected',
            status: PaymentStatus::initialFor($method ?: null, $amount),
            amount: number_format(max(0, (float) $amount), 3, '.', ''),
            currency: strtoupper($currency ?: 'OMR'),
            gateway: 'pending_gateway',
            intentId: $context['idempotency_key'] ?? null,
            metadata: [
                'gateway_pending' => true,
                'context' => $context,
            ],
        );
    }
}
