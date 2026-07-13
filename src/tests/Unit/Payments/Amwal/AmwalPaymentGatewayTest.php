<?php

namespace Tests\Unit\Payments\Amwal;

use App\Services\Checkout\AmwalPaymentGateway;
use App\Services\Checkout\PendingPaymentGateway;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AmwalPaymentGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('Payment_Gateway_Events_T');
        Schema::dropIfExists('Payment_Gateway_Attempts_T');
        Schema::dropIfExists('Sales_Transactions_Details_T');
        Schema::dropIfExists('Orders_Placed_T');

        Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->string('Payment_Status');
            $table->string('Payment_Method');
        });
        Schema::create('Sales_Transactions_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('Payment_Status')->nullable();
            $table->string('Payment_Gateway')->nullable();
            $table->string('Payment_Intent_Id')->nullable();
            $table->text('Payment_Metadata')->nullable();
        });
        Schema::create('Payment_Gateway_Attempts_T', fn (Blueprint $table) => $table->id());
        Schema::create('Payment_Gateway_Events_T', fn (Blueprint $table) => $table->id());

        config()->set('services.amwal.enabled', true);
        config()->set('services.amwal.environment', 'uat');
        config()->set('services.amwal.merchant_id', '48804');
        config()->set('services.amwal.terminal_id', '113176');
        config()->set('services.amwal.secure_key', str_repeat('ab', 32));
        config()->set('services.amwal.smartbox_url', 'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('Payment_Gateway_Events_T');
        Schema::dropIfExists('Payment_Gateway_Attempts_T');
        Schema::dropIfExists('Sales_Transactions_Details_T');
        Schema::dropIfExists('Orders_Placed_T');

        parent::tearDown();
    }

    public function test_valid_card_checkout_creates_only_a_server_pending_amwal_intent(): void
    {
        $intent = $this->gateway()->createIntent(
            method: 'card',
            amount: '12.500',
            currency: 'OMR',
            paymentPayload: ['card' => ['number' => 'must-not-be-used']],
            context: ['order_code' => 'ORDER-1'],
        );

        $this->assertSame('card', $intent->method);
        $this->assertSame('pending', $intent->status);
        $this->assertSame('amwal_smartbox', $intent->gateway);
        $this->assertSame('12.500', $intent->amount);
        $this->assertSame(['environment' => 'uat', 'requires_customer_action' => true], $intent->metadata);
    }

    public function test_card_checkout_fails_closed_for_disabled_invalid_or_non_omr_configuration(): void
    {
        $invalidCalls = [
            function () {
                config()->set('services.amwal.enabled', false);
                return $this->gateway()->createIntent('card', '1.000', 'OMR');
            },
            function () {
                config()->set('services.amwal.enabled', true);
                config()->set('services.amwal.smartbox_url', 'https://example.com/SmartBox.js');
                return $this->gateway()->createIntent('card', '1.000', 'OMR');
            },
            function () {
                config()->set('services.amwal.smartbox_url', 'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1');
                return $this->gateway()->createIntent('card', '1.000', 'USD');
            },
            fn () => $this->gateway()->createIntent('card', '0.000', 'OMR'),
        ];

        foreach ($invalidCalls as $call) {
            try {
                $call();
                $this->fail('Invalid AmwalPay checkout readiness should have failed closed.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function gateway(): AmwalPaymentGateway
    {
        return new AmwalPaymentGateway(new PendingPaymentGateway());
    }
}
