<?php

namespace App\Services\Checkout;

final readonly class PaymentIntent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $method,
        public string $status,
        public string $amount,
        public string $currency,
        public string $gateway,
        public ?string $intentId = null,
        public array $metadata = [],
    ) {
    }
}
