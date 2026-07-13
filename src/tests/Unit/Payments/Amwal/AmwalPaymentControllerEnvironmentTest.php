<?php

namespace Tests\Unit\Payments\Amwal;

use App\Http\Controllers\AmwalPaymentController;
use App\Services\Payments\Amwal\AmwalPaymentAttemptEnvironment;
use App\Services\Payments\Amwal\AmwalPaymentException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AmwalPaymentControllerEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.amwal.enabled', true);
        config()->set('services.amwal.environment', 'production');
        config()->set('services.amwal.merchant_id', '999001');
        config()->set('services.amwal.terminal_id', '999002');
        config()->set('services.amwal.secure_key', str_repeat('AB', 32));
        config()->set('services.amwal.currency_id', '512');
        config()->set('services.amwal.smartbox_url', 'https://checkout.amwalpg.com/js/SmartBox.js?v=1.1');
        config()->set('services.amwal.retry_cooldown_seconds', 120);

        $this->createSchema();
        $this->seedMismatchedAttempt();
    }

    public function test_configuration_refuses_a_pending_attempt_from_another_environment(): void
    {
        $user = (object) [
            'id' => 700,
            'customers' => (object) ['id' => 77],
        ];
        Auth::shouldReceive('user')->once()->andReturn($user);

        try {
            (new AmwalPaymentController)->configuration(
                Request::create('/api/payments/amwal/orders/10/configuration', 'POST', ['language' => 'en']),
                10,
            );
            $this->fail('A UAT attempt must not be resumed with production credentials.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertStringContainsString('different AmwalPay environment', $exception->getMessage());
        }

        $this->assertSame('pending', DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Status'));
        $this->assertSame('uat', json_decode(
            (string) DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Metadata'),
            true,
        )['environment']);
    }

    public function test_production_environment_aliases_are_compatible(): void
    {
        $this->assertTrue(AmwalPaymentAttemptEnvironment::matches('prod', 'production'));
        $this->assertTrue(AmwalPaymentAttemptEnvironment::matches('production', 'prod'));
        $this->assertTrue(AmwalPaymentAttemptEnvironment::matches(null, 'production'));
        $this->assertFalse(AmwalPaymentAttemptEnvironment::matches('uat', 'production'));
    }

    public function test_configuration_refreshes_active_attempt_activity_for_expiry_safety(): void
    {
        $staleAt = now()->subHour();
        DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->update([
            'Metadata' => '{"environment":"production"}',
            'Initiated_At' => $staleAt,
            'created_at' => $staleAt,
            'updated_at' => $staleAt,
        ]);

        Auth::shouldReceive('user')->once()->andReturn((object) [
            'id' => 700,
            'customers' => (object) ['id' => 77],
        ]);

        $response = (new AmwalPaymentController)->configuration(
            Request::create('/api/payments/amwal/orders/10/configuration', 'POST', ['language' => 'en']),
            10,
        );
        $metadata = json_decode(
            (string) DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Metadata'),
            true,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('production', $metadata['environment']);
        $this->assertNotEmpty($metadata['last_configuration_requested_at']);
        $this->assertGreaterThan(
            $staleAt->getTimestamp(),
            (new \DateTimeImmutable($metadata['last_configuration_requested_at']))->getTimestamp(),
        );
        $this->assertSame('pending', DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Status'));
    }

    public function test_configuration_cannot_cross_a_recently_cancelled_attempt(): void
    {
        $this->seedPriorOrderAndAttempt('cancelled', 'cancelled');
        Auth::shouldReceive('user')->once()->andReturn((object) [
            'id' => 700,
            'customers' => (object) ['id' => 77],
        ]);

        try {
            (new AmwalPaymentController)->configuration(
                Request::create('/api/payments/amwal/orders/10/configuration', 'POST', ['language' => 'en']),
                10,
            );
            $this->fail('A new SmartBox configuration must wait for the cancelled attempt cooldown.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertStringContainsString('still reconciling', $exception->getMessage());
        }

        $this->assertSame('uat', json_decode(
            (string) DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Metadata'),
            true,
        )['environment']);
    }

    public function test_configuration_rechecks_customer_level_review_before_opening(): void
    {
        $this->seedPriorOrderAndAttempt('cancelled', 'paid_requires_review');
        Auth::shouldReceive('user')->once()->andReturn((object) [
            'id' => 700,
            'customers' => (object) ['id' => 77],
        ]);

        try {
            (new AmwalPaymentController)->configuration(
                Request::create('/api/payments/amwal/orders/10/configuration', 'POST', ['language' => 'en']),
                10,
            );
            $this->fail('A customer-level captured-payment review must block SmartBox configuration.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertStringContainsString('requires reconciliation', $exception->getMessage());
        }
    }

    private function seedMismatchedAttempt(): void
    {
        DB::table('Customers_Master_T')->insert(['id' => 77]);
        DB::table('Orders_Placed_T')->insert([
            'id' => 10,
            'Order_Code' => 'ORD-ENV-TEST',
            'Customers_Id' => 77,
            'Total_Price' => 10.500,
            'Payment_Method' => 'card',
            'Payment_Status' => 'pending',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Sales_Transaction_Header_T')->insert([
            'id' => 15,
            'Orders_Placed_Id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Sales_Transactions_Details_T')->insert([
            'id' => 20,
            'Sales_Transaction_Header_Id' => 15,
            'Payment_Amount' => 10.500,
            'Payment_Status' => 'pending',
            'Payment_Gateway' => 'amwal_smartbox',
            'Payment_Intent_Id' => 'ORD-ENV-TEST',
            'Payment_Metadata' => '{"environment":"uat"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Payment_Gateway_Attempts_T')->insert([
            'id' => 30,
            'Orders_Placed_Id' => 10,
            'Sales_Transactions_Details_Id' => 20,
            'Gateway' => 'amwal_smartbox',
            'Merchant_Reference' => 'ORD-ENV-TEST-ATTEMPT',
            'Amount' => 10.500,
            'Currency' => 'OMR',
            'Currency_Id' => '512',
            'Status' => 'pending',
            'Initiated_At' => now(),
            'Metadata' => '{"environment":"uat"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPriorOrderAndAttempt(string $orderStatus, string $paymentStatus): void
    {
        DB::table('Orders_Placed_T')->insert([
            'id' => 9,
            'Order_Code' => 'ORD-PRIOR-ATTEMPT',
            'Customers_Id' => 77,
            'Total_Price' => 10.500,
            'Payment_Method' => 'card',
            'Payment_Status' => $paymentStatus,
            'Status' => $orderStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Payment_Gateway_Attempts_T')->insert([
            'id' => 29,
            'Orders_Placed_Id' => 9,
            'Sales_Transactions_Details_Id' => 20,
            'Gateway' => 'amwal_smartbox',
            'Merchant_Reference' => 'ORD-PRIOR-ATTEMPT-REF',
            'Amount' => 10.500,
            'Currency' => 'OMR',
            'Currency_Id' => '512',
            'Status' => $paymentStatus,
            'Initiated_At' => now(),
            'Metadata' => '{"environment":"production"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'Payment_Gateway_Events_T',
            'Payment_Gateway_Attempts_T',
            'Sales_Transactions_Details_T',
            'Sales_Transaction_Header_T',
            'Orders_Placed_T',
            'Customers_Master_T',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('Customers_Master_T', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->string('Order_Code');
            $table->unsignedBigInteger('Customers_Id');
            $table->decimal('Total_Price', 18, 3);
            $table->string('Payment_Method');
            $table->string('Payment_Status');
            $table->string('Status');
            $table->timestamps();
        });
        Schema::create('Sales_Transaction_Header_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->timestamps();
        });
        Schema::create('Sales_Transactions_Details_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Sales_Transaction_Header_Id');
            $table->decimal('Payment_Amount', 18, 3);
            $table->string('Payment_Status');
            $table->string('Payment_Gateway');
            $table->string('Payment_Intent_Id')->nullable();
            $table->text('Payment_Metadata')->nullable();
            $table->string('Card_Gateway')->nullable();
            $table->string('Card_Transaction_Id')->nullable();
            $table->string('Card_Error_Code')->nullable();
            $table->text('Card_Error_Message')->nullable();
            $table->timestamps();
        });
        Schema::create('Payment_Gateway_Attempts_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->unsignedBigInteger('Sales_Transactions_Details_Id');
            $table->string('Gateway');
            $table->string('Merchant_Reference')->unique();
            $table->decimal('Amount', 18, 3);
            $table->string('Currency');
            $table->string('Currency_Id');
            $table->string('Status');
            $table->dateTime('Initiated_At');
            $table->text('Metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('Payment_Gateway_Events_T', function (Blueprint $table) {
            $table->id();
        });
    }
}
