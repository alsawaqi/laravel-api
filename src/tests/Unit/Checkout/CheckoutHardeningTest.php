<?php

namespace Tests\Unit\Checkout;

use App\Services\Checkout\CheckoutIdempotency;
use App\Services\Checkout\LoyaltyRedemptionCalculator;
use App\Services\Checkout\PendingPaymentGateway;
use App\Services\Checkout\PaymentStatus;
use App\Services\Checkout\ShippingQuoteSelection;
use App\Services\Checkout\StockDeduction;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CheckoutHardeningTest extends TestCase
{
    public function test_payment_statuses_are_gateway_independent_placeholders(): void
    {
        $this->assertSame([
            'unpaid',
            'pending',
            'paid',
            'failed',
            'refunded',
            'partially_refunded',
        ], PaymentStatus::values());

        $this->assertSame('pending', PaymentStatus::initialFor('card', '42.000'));
        $this->assertSame('pending', PaymentStatus::initialFor('transfer', '42.000'));
        $this->assertSame('pending', PaymentStatus::initialFor('cod', '42.000'));
        $this->assertSame('paid', PaymentStatus::initialFor('loyalty', '0.000'));
        $this->assertSame('unpaid', PaymentStatus::initialFor(null, '42.000'));
    }

    public function test_checkout_fingerprint_is_stable_for_the_same_cart_and_payload(): void
    {
        $cartRows = [
            ['id' => 9, 'Products_Id' => 3, 'Quantity' => 2],
            ['id' => 10, 'Products_Id' => 7, 'Quantity' => 1],
        ];

        $payload = [
            'delivery_method' => 'ship',
            'Customers_Contacts_Id' => 55,
            'shipping_option' => [
                'shipper_id' => 1,
                'destination_id' => 2,
                'basis' => 'weight',
                'price' => '3.000',
            ],
            'payment' => ['method' => 'cod'],
        ];

        $this->assertSame(
            CheckoutIdempotency::fingerprint(12, $cartRows, $payload),
            CheckoutIdempotency::fingerprint(12, array_reverse($cartRows), $payload),
        );

        $changedPayload = $payload;
        $changedPayload['shipping_option']['price'] = '4.000';

        $this->assertNotSame(
            CheckoutIdempotency::fingerprint(12, $cartRows, $payload),
            CheckoutIdempotency::fingerprint(12, $cartRows, $changedPayload),
        );
    }

    public function test_request_idempotency_key_is_sanitized_for_storage(): void
    {
        $this->assertSame(
            'retry-key_123',
            CheckoutIdempotency::requestKey(' retry-key_123 !@# ', null),
        );

        $this->assertNull(CheckoutIdempotency::requestKey(null, ''));
    }

    public function test_pending_payment_gateway_creates_placeholder_intent(): void
    {
        $intent = (new PendingPaymentGateway())->createIntent(
            method: 'card',
            amount: '9.500',
            currency: 'omr',
            context: ['idempotency_key' => 'checkout-123'],
        );

        $this->assertSame('card', $intent->method);
        $this->assertSame('pending', $intent->status);
        $this->assertSame('9.500', $intent->amount);
        $this->assertSame('OMR', $intent->currency);
        $this->assertSame('pending_gateway', $intent->gateway);
        $this->assertSame('checkout-123', $intent->intentId);
    }

    public function test_shipping_quote_selection_rejects_expired_quotes_and_matches_current_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShippingQuoteSelection::assertNotExpired('2026-05-04 09:59:00', '2026-05-04 10:00:00');
    }

    public function test_shipping_quote_selection_finds_matching_recalculated_option(): void
    {
        $option = ShippingQuoteSelection::match([
            [
                'shipper_id' => 5,
                'destination_id' => 8,
                'basis' => 'weight',
                'total_price' => 2.345,
                'currency' => 'OMR',
            ],
        ], [
            'shipper_id' => 5,
            'destination_id' => 8,
            'basis' => 'weight',
            'price' => '2.345',
        ]);

        $this->assertSame(2.345, $option['total_price']);
    }

    public function test_loyalty_redemption_is_capped_by_available_points_and_payable_total(): void
    {
        $result = LoyaltyRedemptionCalculator::calculate(
            requestedPoints: 1000,
            availablePoints: 800,
            orderTotal: '3.500',
            redeemRulePoints: '100.000',
            redeemRuleAmount: '1.000',
        );

        $this->assertSame(350, $result['points']);
        $this->assertSame('3.500', $result['amount']);
    }

    public function test_stock_deduction_rejects_overselling_and_returns_remaining_quantity(): void
    {
        $this->assertSame(3, StockDeduction::remainingAfterDeduction(5, 2));

        $this->expectException(InvalidArgumentException::class);
        StockDeduction::remainingAfterDeduction(1, 2);
    }
}
