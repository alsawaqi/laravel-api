<?php

namespace Tests\Unit\Checkout;

use App\Http\Controllers\OrdersPlacedController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrdersPlacedStaleCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.amwal.retry_cooldown_seconds', 120);
        $this->createSchema();
        DB::table('Customers_Master_T')->insert(['id' => 77]);
        DB::table('Geox_Location_Master_T')->insert(['id' => 1]);
    }

    public function test_existing_explicit_key_with_a_nonempty_server_cart_returns_conflict(): void
    {
        $this->seedCart();
        $this->seedOrder('checkout-old');
        $this->authenticateCustomer();

        $response = (new OrdersPlacedController)->place($this->checkoutRequest('checkout-old'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('STALE_CHECKOUT_KEY', $response->getData(true)['code']);
        $this->assertSame(10, $response->getData(true)['active_order']['order_id']);
        $this->assertSame(1, DB::table('Customers_Carts_T')->count());
        $this->assertSame(1, DB::table('Orders_Placed_T')->count());
    }

    public function test_new_key_cannot_create_a_second_active_unpaid_card_order(): void
    {
        $this->seedCart();
        $this->seedOrder('checkout-old');
        $this->authenticateCustomer();

        $response = (new OrdersPlacedController)->place($this->checkoutRequest('checkout-new'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('ACTIVE_AMWAL_ORDER_EXISTS', $response->getData(true)['code']);
        $this->assertSame(10, $response->getData(true)['active_order']['order_id']);
        $this->assertSame(1, DB::table('Customers_Carts_T')->count());
        $this->assertSame(1, DB::table('Orders_Placed_T')->count());
    }

    public function test_new_card_checkout_is_blocked_while_a_late_capture_requires_review(): void
    {
        $this->seedCart();
        $this->seedOrder('checkout-old');
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'Status' => 'cancelled',
            'Payment_Status' => 'paid_requires_review',
        ]);
        $this->authenticateCustomer();

        $response = (new OrdersPlacedController)->place($this->checkoutRequest('checkout-new'));

        $this->assertSame(409, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('AMWAL_RECONCILIATION_REQUIRED', $payload['code']);
        $this->assertSame(10, $payload['active_order']['order_id']);
        $this->assertSame('paid_requires_review', $payload['active_order']['payment_status']);
        $this->assertSame(1, DB::table('Customers_Carts_T')->count());
        $this->assertSame(1, DB::table('Orders_Placed_T')->count());
    }

    public function test_review_attempt_alone_blocks_a_new_card_checkout_if_the_order_header_is_stale(): void
    {
        $this->seedCart();
        $this->seedOrder('checkout-old');
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'Status' => 'cancelled',
            'Payment_Status' => 'cancelled',
        ]);
        DB::table('Payment_Gateway_Attempts_T')->insert([
            'Orders_Placed_Id' => 10,
            'Gateway' => 'amwal_smartbox',
            'Status' => 'paid_requires_review',
        ]);
        $this->authenticateCustomer();

        $response = (new OrdersPlacedController)->place($this->checkoutRequest('checkout-new'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('AMWAL_RECONCILIATION_REQUIRED', $response->getData(true)['code']);
        $this->assertSame('paid_requires_review', $response->getData(true)['active_order']['payment_status']);
        $this->assertSame(1, DB::table('Customers_Carts_T')->count());
    }

    public function test_recently_cancelled_attempt_blocks_a_fresh_card_order_but_keeps_the_cart(): void
    {
        $this->seedCart();
        $this->seedOrder('checkout-old');
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'Status' => 'cancelled',
            'Payment_Status' => 'cancelled',
            'updated_at' => now(),
        ]);
        DB::table('Payment_Gateway_Attempts_T')->insert([
            'Orders_Placed_Id' => 10,
            'Gateway' => 'amwal_smartbox',
            'Status' => 'cancelled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->authenticateCustomer();

        $response = (new OrdersPlacedController)->place($this->checkoutRequest('checkout-new'));
        $payload = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('AMWAL_RETRY_COOLDOWN', $payload['code']);
        $this->assertGreaterThan(0, $payload['retry_after_seconds']);
        $this->assertLessThanOrEqual(120, $payload['retry_after_seconds']);
        $this->assertSame(10, $payload['previous_order']['order_id']);
        $this->assertSame(1, DB::table('Customers_Carts_T')->count());
        $this->assertSame(1, DB::table('Orders_Placed_T')->count());
    }

    public function test_empty_cart_retry_with_the_same_key_returns_the_original_order(): void
    {
        $this->seedOrder('checkout-old');
        $this->authenticateCustomer();

        $response = (new OrdersPlacedController)->place($this->checkoutRequest('checkout-old'));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['idempotent']);
        $this->assertSame(10, $payload['order_id']);
    }

    public function test_customer_can_reconcile_a_lost_card_checkout_response_by_sanitized_key(): void
    {
        $this->seedOrder('checkout-old');
        $this->authenticateCustomer();
        $request = Request::create(
            '/api/orders/checkout-reconciliation?checkout_request_key=checkout-!old',
            'GET',
        );

        $response = (new OrdersPlacedController)->reconcileCheckout($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['idempotent']);
        $this->assertSame(10, $payload['order_id']);
        $this->assertSame('card', $payload['payment']['method']);
        $this->assertSame('/api/payments/amwal/orders/10/status', $payload['payment']['status_url']);
    }

    public function test_checkout_reconciliation_is_customer_scoped_and_card_only(): void
    {
        $this->seedOrder('checkout-old');
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Customers_Id' => 88]);
        $this->authenticateCustomer();

        $response = (new OrdersPlacedController)->reconcileCheckout(
            Request::create('/api/orders/checkout-reconciliation?checkout_request_key=checkout-old', 'GET'),
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    private function authenticateCustomer(): void
    {
        Auth::shouldReceive('user')->once()->andReturn((object) [
            'id' => 700,
            'customers' => (object) ['id' => 77],
        ]);
    }

    private function checkoutRequest(string $key): Request
    {
        $request = Request::create('/api/orders/place', 'POST', [
            'delivery_method' => 'pickup',
            'location_id' => 1,
            'shipping_cost' => 0,
            'idempotency_key' => $key,
            'payment' => [
                'method' => 'card',
                'currency' => 'OMR',
                'amount' => 1.575,
            ],
        ]);
        $request->headers->set('Idempotency-Key', $key);

        return $request;
    }

    private function seedCart(): void
    {
        DB::table('Customers_Carts_T')->insert([
            'id' => 100,
            'Customers_Id' => 77,
            'Products_Id' => 999,
            'Quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOrder(string $checkoutKey): void
    {
        DB::table('Orders_Placed_T')->insert([
            'id' => 10,
            'Order_Code' => 'ORD-OLD',
            'Customers_Id' => 77,
            'Total_Price' => 10.500,
            'Payment_Method' => 'card',
            'Payment_Status' => 'pending',
            'Status' => 'pending',
            'Checkout_Request_Key' => $checkoutKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'Payment_Gateway_Attempts_T',
            'Orders_Placed_T',
            'Customers_Carts_T',
            'Geox_Location_Master_T',
            'Customers_Master_T',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('Customers_Master_T', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('Geox_Location_Master_T', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('Customers_Carts_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Customers_Id');
            $table->unsignedBigInteger('Products_Id');
            $table->integer('Quantity');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->string('Order_Code');
            $table->unsignedBigInteger('Customers_Id');
            $table->decimal('Total_Price', 18, 3);
            $table->string('Payment_Method');
            $table->string('Payment_Status');
            $table->string('Status');
            $table->string('Checkout_Request_Key')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('Payment_Gateway_Attempts_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->string('Gateway');
            $table->string('Status');
            $table->timestamps();
        });
    }
}
