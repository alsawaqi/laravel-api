<?php

namespace Tests\Unit\Payments\Amwal;

use App\Http\Controllers\AmwalPaymentController;
use App\Http\Controllers\OrdersPlacedController;
use App\Services\Orders\CustomerUnpaidAmwalOrderCancellationService;
use App\Services\Payments\Amwal\AmwalPaymentException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerUnpaidAmwalOrderCancellationServiceTest extends TestCase
{
    private CustomerUnpaidAmwalOrderCancellationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->seedPendingOrder();
        $this->service = new CustomerUnpaidAmwalOrderCancellationService;
    }

    public function test_customer_cancellation_releases_stock_and_loyalty_exactly_once(): void
    {
        $result = $this->service->cancel(10, 77, 700, 'Test customer');

        $this->assertFalse($result['idempotent']);
        $this->assertSame(1, $result['released_lines']);
        $this->assertSame(100, $result['released_loyalty_points']);
        $this->assertTrue($result['cart_restoration']['requested']);
        $this->assertTrue($result['cart_restoration']['performed']);
        $this->assertSame(5, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_T')->where('id', 10)->value('Status'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_T')->where('id', 10)->value('Payment_Status'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_Details_T')->where('id', 40)->value('Status'));
        $this->assertSame('cancelled', DB::table('Orders_Placed_Vendors_T')->where('id', 60)->value('Status'));
        $this->assertSame('cancelled', DB::table('Sales_Transactions_Details_T')->where('id', 20)->value('Payment_Status'));
        $this->assertSame('cancelled', DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Status'));
        $this->assertSame(0, (int) DB::table('Customers_Loyalty_T')->where('id', 80)->value('Points_Redeemed'));
        $this->assertSame(-100, (int) DB::table('Customers_Loyalty_Transactions_T')
            ->where('Loyalty_Transaction_Code', 'LOYREV-10')
            ->value('Points_Redeemed'));
        $this->assertSame(1, DB::table('Product_Stock_Movements_T')->count());
        $this->assertSame(1, DB::table('Payment_Gateway_Events_T')->count());

        $audit = DB::table('Order_Process_Log_T')->first();
        $this->assertNull($audit->Actor_User_Id);
        $this->assertSame('customer', $audit->Actor_Role);
        $this->assertSame('Test customer', $audit->Actor_Name);

        $again = $this->service->cancel(
            orderId: 10,
            customerId: 77,
            customerUserId: 700,
            actorName: 'Test customer',
            restoreCart: true,
        );

        $this->assertTrue($again['idempotent']);
        $this->assertTrue($again['cart_restoration']['requested']);
        $this->assertTrue($again['cart_restoration']['performed']);
        $this->assertSame(1, DB::table('Customers_Carts_T')->count());
        $this->assertSame(2, (int) DB::table('Customers_Carts_T')->value('Quantity'));
        $this->assertSame(5, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame(1, DB::table('Product_Stock_Movements_T')->count());
        $this->assertSame(1, DB::table('Payment_Gateway_Events_T')->count());
        $this->assertSame(1, DB::table('Customers_Loyalty_Transactions_T')
            ->where('Loyalty_Transaction_Code', 'LOYREV-10')
            ->count());
    }

    public function test_customer_cannot_cancel_another_customers_order(): void
    {
        try {
            $this->service->cancel(10, 88, 800, 'Other customer');
            $this->fail('Cross-customer cancellation must be rejected.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(404, $exception->status);
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->where('id', 10)->value('Status'));
        $this->assertSame(3, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame(100, (int) DB::table('Customers_Loyalty_T')->where('id', 80)->value('Points_Redeemed'));
    }

    public function test_customer_endpoint_returns_nonpayable_payment_and_cancellation_payloads(): void
    {
        Auth::shouldReceive('user')->once()->andReturn((object) [
            'id' => 700,
            'User_Name' => 'Test customer',
            'customers' => (object) ['id' => 77],
        ]);

        $response = (new AmwalPaymentController)->cancel(
            Request::create('/api/payments/amwal/orders/10/cancel', 'POST', ['restore_cart' => false]),
            10,
        );
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(10, $payload['payment']['order_id']);
        $this->assertSame('cancelled', $payload['payment']['status']);
        $this->assertFalse($payload['payment']['paid']);
        $this->assertFalse($payload['payment']['payable']);
        $this->assertFalse($payload['payment']['requires_action']);
        $this->assertFalse($payload['cancellation']['idempotent']);
        $this->assertSame(1, $payload['cancellation']['released_lines']);
        $this->assertSame(100, $payload['cancellation']['released_loyalty_points']);
        $this->assertTrue($payload['cancellation']['cart_restoration']['requested']);
        $this->assertTrue($payload['cancellation']['cart_restoration']['performed']);
        $this->assertSame(2, $payload['cancellation']['cart_restoration']['restored_quantity']);
        $this->assertSame(2, (int) DB::table('Customers_Carts_T')
            ->whereNull('deleted_at')
            ->where('Products_Id', 50)
            ->value('Quantity'));
    }

    public function test_cancelled_card_draft_is_hidden_from_customer_order_surfaces(): void
    {
        $this->service->cancel(10, 77, 700, 'Test customer');
        DB::table('Orders_Placed_T')->insert([
            'id' => 11,
            'Order_Code' => 'ORD-PAID-TEST',
            'Customers_Id' => 77,
            'Total_Price' => 5.000,
            'Payment_Method' => 'card',
            'Payment_Status' => 'paid',
            'Status' => 'pending',
            'Loyalty_Points_Redeemed' => 0,
            'Loyalty_Discount_Amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Auth::shouldReceive('user')->twice()->andReturn((object) [
            'id' => 700,
            'customers' => (object) ['id' => 77],
        ]);

        $index = (new OrdersPlacedController)->index(Request::create('/api/orders', 'GET'));
        $ids = collect($index->getData(true)['data'])->pluck('id')->all();

        $this->assertSame([11], $ids);
        $this->assertSame(
            404,
            (new OrdersPlacedController)->getOrderDetails(10)->getStatusCode(),
        );
    }

    public function test_requested_cart_restoration_adds_order_quantities_and_preserves_newer_lines_once(): void
    {
        DB::table('Products_Master_T')->insert([
            'id' => 51,
            'Product_Stock' => 20,
            'Status' => 'available',
            'Is_Active' => 1,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Customers_Carts_T')->insert([
            [
                'id' => 100,
                'Customers_Id' => 77,
                'Products_Id' => 50,
                'Quantity' => 3,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 101,
                'Customers_Id' => 77,
                'Products_Id' => 51,
                'Quantity' => 7,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $result = $this->service->cancel(
            orderId: 10,
            customerId: 77,
            customerUserId: 700,
            actorName: 'Test customer',
            restoreCart: true,
        );

        $this->assertTrue($result['cart_restoration']['requested']);
        $this->assertTrue($result['cart_restoration']['performed']);
        $this->assertSame(1, $result['cart_restoration']['restored_lines']);
        $this->assertSame(2, $result['cart_restoration']['restored_quantity']);
        $this->assertSame('increased', $result['cart_restoration']['restored'][0]['action']);
        $this->assertSame([40], $result['cart_restoration']['restored'][0]['order_detail_ids']);
        $this->assertSame(0, $result['cart_restoration']['skipped_lines']);
        $this->assertFalse($result['cart_restoration']['review_required']);
        $this->assertSame(5, (int) DB::table('Customers_Carts_T')->where('id', 100)->value('Quantity'));
        $this->assertSame(7, (int) DB::table('Customers_Carts_T')->where('id', 101)->value('Quantity'));

        $again = $this->service->cancel(
            orderId: 10,
            customerId: 77,
            customerUserId: 700,
            actorName: 'Test customer',
            restoreCart: true,
        );

        $this->assertTrue($again['idempotent']);
        $this->assertSame($result['cart_restoration'], $again['cart_restoration']);
        $this->assertSame(5, (int) DB::table('Customers_Carts_T')->where('id', 100)->value('Quantity'));

        $paymentMetadata = json_decode(
            (string) DB::table('Sales_Transactions_Details_T')->where('id', 20)->value('Payment_Metadata'),
            true,
        );
        $this->assertSame($result['cart_restoration'], $paymentMetadata['cart_restoration']);
    }

    public function test_cart_restoration_creates_a_new_active_line_without_resurrecting_a_soft_deleted_line(): void
    {
        DB::table('Customers_Carts_T')->insert([
            'id' => 100,
            'Customers_Id' => 77,
            'Products_Id' => 50,
            'Quantity' => 9,
            'deleted_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinute(),
        ]);

        $result = $this->service->cancel(
            orderId: 10,
            customerId: 77,
            customerUserId: 700,
            actorName: 'Test customer',
            restoreCart: true,
        );

        $this->assertSame('created', $result['cart_restoration']['restored'][0]['action']);
        $this->assertNotSame(100, $result['cart_restoration']['restored'][0]['cart_id']);
        $this->assertNotNull(DB::table('Customers_Carts_T')->where('id', 100)->value('deleted_at'));
        $this->assertSame(9, (int) DB::table('Customers_Carts_T')->where('id', 100)->value('Quantity'));
        $this->assertSame(1, DB::table('Customers_Carts_T')
            ->where('Customers_Id', 77)
            ->where('Products_Id', 50)
            ->whereNull('deleted_at')
            ->count());
        $this->assertSame(2, (int) DB::table('Customers_Carts_T')
            ->where('Customers_Id', 77)
            ->where('Products_Id', 50)
            ->whereNull('deleted_at')
            ->value('Quantity'));
    }

    public function test_inactive_product_is_skipped_for_cart_and_is_not_reactivated_during_stock_release(): void
    {
        DB::table('Products_Master_T')->where('id', 50)->update([
            'Status' => 'out_of_stock',
            'Is_Active' => 0,
        ]);

        $result = $this->service->cancel(
            orderId: 10,
            customerId: 77,
            customerUserId: 700,
            actorName: 'Test customer',
            restoreCart: true,
        );

        $this->assertSame(5, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame('out_of_stock', DB::table('Products_Master_T')->where('id', 50)->value('Status'));
        $this->assertSame(0, DB::table('Customers_Carts_T')->whereNull('deleted_at')->count());
        $this->assertSame(0, $result['cart_restoration']['restored_lines']);
        $this->assertSame(1, $result['cart_restoration']['skipped_lines']);
        $this->assertSame(2, $result['cart_restoration']['skipped_quantity']);
        $this->assertSame('product_inactive', $result['cart_restoration']['skipped'][0]['reason']);
        $this->assertSame([40], $result['cart_restoration']['skipped'][0]['order_detail_ids']);
        $this->assertTrue($result['cart_restoration']['review_required']);
    }

    public function test_system_expiry_restores_the_cart_as_a_crash_fallback(): void
    {
        $result = $this->service->cancel(
            orderId: 10,
            customerId: 77,
            customerUserId: 0,
            actorName: 'Automated AmwalPay expiry',
            source: 'system_expiry',
            restoreCart: true,
        );

        $this->assertTrue($result['cart_restoration']['requested']);
        $this->assertTrue($result['cart_restoration']['performed']);
        $this->assertNull($result['cart_restoration']['ignored_reason']);
        $this->assertSame(2, (int) DB::table('Customers_Carts_T')->value('Quantity'));
    }

    public function test_unknown_cancellation_source_cannot_be_treated_as_a_customer_restore(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->service->cancel(
                orderId: 10,
                customerId: 77,
                customerUserId: 0,
                source: 'system_expiryy',
                restoreCart: true,
            );
        } finally {
            $this->assertSame(0, DB::table('Customers_Carts_T')->count());
            $this->assertSame('pending', DB::table('Orders_Placed_T')->where('id', 10)->value('Status'));
        }
    }

    public function test_legacy_cancelled_order_without_restoration_metadata_is_not_restored_on_retry(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'Status' => 'cancelled',
            'Payment_Status' => 'cancelled',
        ]);
        DB::table('Orders_Placed_Details_T')->where('id', 40)->update(['Status' => 'cancelled']);

        $result = $this->service->cancel(
            orderId: 10,
            customerId: 77,
            customerUserId: 700,
            actorName: 'Test customer',
            restoreCart: true,
        );

        $this->assertTrue($result['idempotent']);
        $this->assertTrue($result['cart_restoration']['requested']);
        $this->assertFalse($result['cart_restoration']['performed']);
        $this->assertSame('order_already_cancelled', $result['cart_restoration']['ignored_reason']);
        $this->assertSame(0, DB::table('Customers_Carts_T')->count());
        $this->assertSame(3, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
    }

    public function test_captured_payment_cannot_be_cancelled_or_restocked(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Payment_Status' => 'paid']);
        DB::table('Sales_Transactions_Details_T')->where('id', 20)->update(['Payment_Status' => 'paid']);
        DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->update([
            'Status' => 'paid',
            'Gateway_Transaction_Id' => 'CAPTURED-100',
        ]);

        try {
            $this->service->cancel(10, 77, 700, 'Test customer');
            $this->fail('A captured order must not be cancelled locally.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame(3, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame('pending', DB::table('Orders_Placed_Details_T')->where('id', 40)->value('Status'));
        $this->assertSame(100, (int) DB::table('Customers_Loyalty_T')->where('id', 80)->value('Points_Redeemed'));
        $this->assertSame(0, DB::table('Order_Process_Log_T')->count());
    }

    public function test_expiry_command_reuses_cancellation_path_without_touching_captured_orders(): void
    {
        $staleAt = now()->subHour();
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'created_at' => $staleAt,
        ]);
        DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->update([
            'Initiated_At' => $staleAt,
            'created_at' => $staleAt,
            'updated_at' => $staleAt,
        ]);
        DB::table('Orders_Placed_T')->insert([
            'id' => 11,
            'Order_Code' => 'ORD-CAPTURED-TEST',
            'Customers_Id' => 77,
            'Total_Price' => 8.000,
            'Payment_Method' => 'card',
            'Payment_Status' => 'paid',
            'Status' => 'pending',
            'Loyalty_Points_Redeemed' => 0,
            'Loyalty_Discount_Amount' => 0,
            'created_at' => $staleAt,
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('amwal:expire-unpaid-orders', ['--minutes' => 30]));
        $this->assertSame('cancelled', DB::table('Orders_Placed_T')->where('id', 10)->value('Status'));
        $this->assertSame(5, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame('system', DB::table('Product_Stock_Movements_T')->value('Actor_Type'));
        $this->assertSame('system', DB::table('Order_Process_Log_T')->value('Actor_Role'));
        $paymentMetadata = json_decode(
            (string) DB::table('Sales_Transactions_Details_T')->where('id', 20)->value('Payment_Metadata'),
            true,
        );
        $this->assertSame('system_expiry', $paymentMetadata['cancellation_source']);
        $this->assertTrue($paymentMetadata['cart_restoration']['requested']);
        $this->assertTrue($paymentMetadata['cart_restoration']['performed']);
        $this->assertSame(2, (int) DB::table('Customers_Carts_T')->value('Quantity'));
        $this->assertSame('paid', DB::table('Orders_Placed_T')->where('id', 11)->value('Payment_Status'));
        $this->assertSame('pending', DB::table('Orders_Placed_T')->where('id', 11)->value('Status'));
    }

    public function test_expiry_command_excludes_an_old_order_with_a_recent_active_attempt(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'created_at' => now()->subHour(),
        ]);
        DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->update([
            'Initiated_At' => now()->subHour(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, Artisan::call('amwal:expire-unpaid-orders', ['--minutes' => 30]));
        $this->assertSame('pending', DB::table('Orders_Placed_T')->where('id', 10)->value('Status'));
        $this->assertSame('pending', DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Status'));
        $this->assertSame(3, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame(0, DB::table('Product_Stock_Movements_T')->count());
    }

    public function test_expiry_service_rechecks_recent_attempt_activity_after_candidate_selection(): void
    {
        DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->update([
            'Initiated_At' => now()->subHour(),
            'updated_at' => now(),
        ]);

        try {
            $this->service->cancel(
                orderId: 10,
                customerId: 77,
                customerUserId: 0,
                actorName: 'Automated AmwalPay expiry',
                source: 'system_expiry',
                restoreCart: false,
                expiryCutoff: now()->subMinutes(30),
            );
            $this->fail('A freshly resumed pending attempt must not be expired.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->where('id', 10)->value('Status'));
        $this->assertSame(3, (int) DB::table('Products_Master_T')->where('id', 50)->value('Product_Stock'));
        $this->assertSame(0, DB::table('Customers_Carts_T')->count());
    }

    private function seedPendingOrder(): void
    {
        DB::table('Customers_Master_T')->insert([
            ['id' => 77],
            ['id' => 88],
        ]);
        DB::table('Orders_Placed_T')->insert([
            'id' => 10,
            'Order_Code' => 'ORD-CANCEL-TEST',
            'Customers_Id' => 77,
            'Total_Price' => 10.500,
            'Payment_Method' => 'card',
            'Payment_Status' => 'pending',
            'Status' => 'pending',
            'Loyalty_Points_Redeemed' => 100,
            'Loyalty_Discount_Amount' => 1.000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Products_Master_T')->insert([
            'id' => 50,
            'Product_Stock' => 3,
            'Status' => 'available',
            'Is_Active' => 1,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Orders_Placed_Details_T')->insert([
            'id' => 40,
            'Orders_Placed_Id' => 10,
            'Products_Id' => 50,
            'Vendor_Id' => 90,
            'Quantity' => 2,
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Orders_Placed_Vendors_T')->insert([
            'id' => 60,
            'Orders_Placed_Id' => 10,
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
            'Payment_Gateway' => 'amwal_smartbox',
            'Payment_Status' => 'pending',
            'Payment_Metadata' => '{"environment":"production"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Payment_Gateway_Attempts_T')->insert([
            'id' => 30,
            'Orders_Placed_Id' => 10,
            'Sales_Transactions_Details_Id' => 20,
            'Gateway' => 'amwal_smartbox',
            'Merchant_Reference' => 'ORD-CANCEL-TEST-ATTEMPT',
            'Amount' => 10.500,
            'Currency' => 'OMR',
            'Status' => 'pending',
            'Initiated_At' => now(),
            'Metadata' => '{"environment":"production"}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Customers_Loyalty_T')->insert([
            'id' => 80,
            'Customer_Id' => 77,
            'Points_Earned' => 500,
            'Points_Redeemed' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'Payment_Gateway_Events_T',
            'Payment_Gateway_Attempts_T',
            'Order_Process_Log_T',
            'Product_Stock_Movements_T',
            'Orders_Placed_Vendors_T',
            'Orders_Placed_Details_T',
            'Sales_Transactions_Details_T',
            'Sales_Transaction_Header_T',
            'Customers_Loyalty_Transactions_T',
            'Customers_Loyalty_T',
            'Customers_Carts_T',
            'Products_Master_T',
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
            $table->integer('Loyalty_Points_Redeemed')->default(0);
            $table->decimal('Loyalty_Discount_Amount', 18, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('Products_Master_T', function (Blueprint $table) {
            $table->id();
            $table->integer('Product_Stock');
            $table->string('Status');
            $table->boolean('Is_Active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('Customers_Carts_T', function (Blueprint $table) {
            $table->id();
            $table->string('Cart_Code')->nullable();
            $table->unsignedBigInteger('Customers_Id');
            $table->unsignedBigInteger('Products_Id');
            $table->integer('Quantity');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('Orders_Placed_Details_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->unsignedBigInteger('Products_Id');
            $table->unsignedBigInteger('Vendor_Id')->nullable();
            $table->integer('Quantity');
            $table->string('Status');
            $table->timestamps();
        });
        Schema::create('Orders_Placed_Vendors_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
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
            $table->string('Payment_Gateway');
            $table->string('Payment_Status');
            $table->string('Card_Transaction_Id')->nullable();
            $table->string('Card_Error_Code')->nullable();
            $table->text('Card_Error_Message')->nullable();
            $table->text('Payment_Metadata')->nullable();
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
            $table->string('Status');
            $table->string('Gateway_Transaction_Id')->nullable();
            $table->dateTime('Initiated_At');
            $table->dateTime('Completed_At')->nullable();
            $table->text('Metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('Payment_Gateway_Events_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Payment_Gateway_Attempt_Id');
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->string('Gateway');
            $table->string('Source');
            $table->string('Payload_Digest')->unique();
            $table->string('Merchant_Reference');
            $table->string('Gateway_Transaction_Id')->nullable();
            $table->string('Response_Code')->nullable();
            $table->string('Outcome');
            $table->dateTime('Processed_At');
            $table->timestamps();
        });
        Schema::create('Customers_Loyalty_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Customer_Id');
            $table->integer('Points_Earned')->default(0);
            $table->integer('Points_Redeemed')->default(0);
            $table->timestamps();
        });
        Schema::create('Customers_Loyalty_Transactions_T', function (Blueprint $table) {
            $table->id();
            $table->string('Loyalty_Transaction_Code')->unique();
            $table->unsignedBigInteger('Customer_Id');
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->integer('Points_Earned')->default(0);
            $table->integer('Points_Redeemed')->default(0);
            $table->decimal('Redeemed_Amount', 18, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('Product_Stock_Movements_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Products_Id');
            $table->unsignedBigInteger('Vendor_Id')->nullable();
            $table->string('Movement_Type');
            $table->integer('Quantity_Delta');
            $table->integer('Quantity');
            $table->integer('Previous_Stock');
            $table->integer('New_Stock');
            $table->string('Actor_Type')->nullable();
            $table->unsignedBigInteger('Actor_Id')->nullable();
            $table->string('Actor_Name')->nullable();
            $table->text('Notes')->nullable();
            $table->timestamps();
        });
        Schema::create('Order_Process_Log_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->unsignedBigInteger('Orders_Placed_Details_Id')->nullable();
            $table->unsignedBigInteger('Orders_Placed_Details_Cancelled_Id')->nullable();
            $table->string('Step_Code');
            $table->string('Status');
            $table->boolean('Is_External')->default(false);
            $table->unsignedBigInteger('Actor_User_Id')->nullable();
            $table->string('Actor_Name')->nullable();
            $table->string('Actor_Role')->nullable();
            $table->dateTime('Signed_At')->nullable();
            $table->text('Signature_Url')->nullable();
            $table->string('Signature_Mime')->nullable();
            $table->text('Notes')->nullable();
            $table->timestamps();
        });
    }
}
