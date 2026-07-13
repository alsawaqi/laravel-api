<?php

namespace Tests\Unit\Checkout;

use App\Services\Checkout\LoyaltyEarningPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoyaltyEarningPolicyTest extends TestCase
{
    #[DataProvider('unconfirmedPaymentMethods')]
    public function test_positive_unconfirmed_payments_defer_loyalty_earning(string $method): void
    {
        $this->assertTrue(
            LoyaltyEarningPolicy::deferUntilPaymentConfirmed($method, '10.500'),
        );
    }

    public static function unconfirmedPaymentMethods(): array
    {
        return [
            'card' => ['card'],
            'cash on delivery' => ['cod'],
            'bank transfer' => ['transfer'],
        ];
    }

    public function test_fully_paid_loyalty_order_does_not_wait_for_another_payment(): void
    {
        $this->assertFalse(
            LoyaltyEarningPolicy::deferUntilPaymentConfirmed('loyalty', 0),
        );
    }

    public function test_zero_balance_never_creates_a_deferred_earning(): void
    {
        $this->assertFalse(
            LoyaltyEarningPolicy::deferUntilPaymentConfirmed('cod', '0.000'),
        );
    }
}
