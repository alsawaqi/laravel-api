<?php

namespace App\Services\Checkout;

use RuntimeException;
use Illuminate\Support\Facades\Schema;

final class AmwalPaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly PendingPaymentGateway $fallback,
    ) {
    }

    public function createIntent(
        ?string $method,
        string|int|float $amount,
        string $currency,
        array $paymentPayload = [],
        array $context = [],
    ): PaymentIntent {
        $method = strtolower(trim((string) ($method ?: $paymentPayload['method'] ?? '')));

        if ($method !== 'card') {
            return $this->fallback->createIntent($method, $amount, $currency, $paymentPayload, $context);
        }

        if (!config('services.amwal.enabled')) {
            throw new RuntimeException('Card payments are temporarily unavailable.');
        }

        if (!$this->configurationIsValid()) {
            throw new RuntimeException('Card payments are not configured.');
        }

        if (!Schema::hasTable('Payment_Gateway_Attempts_T')
            || !Schema::hasTable('Payment_Gateway_Events_T')
            || !Schema::hasColumns('Orders_Placed_T', ['Payment_Status', 'Payment_Method'])
            || !Schema::hasColumns('Sales_Transactions_Details_T', [
                'Payment_Status',
                'Payment_Gateway',
                'Payment_Intent_Id',
                'Payment_Metadata',
            ])) {
            throw new RuntimeException('Card payments are not ready.');
        }

        if (strtoupper(trim($currency)) !== 'OMR') {
            throw new RuntimeException('AmwalPay card payments support OMR only.');
        }

        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount <= 0) {
            throw new RuntimeException('The AmwalPay card amount must be greater than zero.');
        }

        $formattedAmount = number_format((float) $amount, 3, '.', '');

        return new PaymentIntent(
            method: 'card',
            status: PaymentStatus::initialFor('card', $formattedAmount),
            amount: $formattedAmount,
            currency: 'OMR',
            gateway: 'amwal_smartbox',
            intentId: isset($context['order_code']) ? (string) $context['order_code'] : null,
            metadata: [
                'environment' => (string) config('services.amwal.environment', 'uat'),
                'requires_customer_action' => true,
            ],
        );
    }

    private function configurationIsValid(): bool
    {
        foreach (['merchant_id', 'terminal_id', 'secure_key', 'smartbox_url'] as $key) {
            if (trim((string) config("services.amwal.{$key}")) === '') {
                return false;
            }
        }

        $secureKey = trim((string) config('services.amwal.secure_key'));
        if (strlen($secureKey) % 2 !== 0 || !ctype_xdigit($secureKey)) {
            return false;
        }

        $expectedUrl = match (strtolower(trim((string) config('services.amwal.environment')))) {
            'uat' => 'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1',
            'production', 'prod' => 'https://checkout.amwalpg.com/js/SmartBox.js?v=1.1',
            default => null,
        };

        return $expectedUrl !== null
            && hash_equals($expectedUrl, trim((string) config('services.amwal.smartbox_url')));
    }
}
