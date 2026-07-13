<?php

namespace Tests\Unit\Payments\Amwal;

use App\Http\Controllers\AmwalNotificationController;
use App\Services\Payments\Amwal\AmwalPaymentException;
use App\Services\Payments\Amwal\AmwalPaymentProcessor;
use App\Services\Payments\Amwal\AmwalSecureHash;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Tests\TestCase;

class AmwalPaymentProcessorTest extends TestCase
{
    private const KEY = '00112233445566778899AABBCCDDEEFF00112233445566778899AABBCCDDEEFF';
    private const MERCHANT_ID = '48804';
    private const TERMINAL_ID = '113176';

    private AmwalSecureHash $hash;
    private AmwalPaymentProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.amwal.merchant_id', self::MERCHANT_ID);
        config()->set('services.amwal.terminal_id', self::TERMINAL_ID);
        config()->set('services.amwal.secure_key', self::KEY);
        config()->set('services.amwal.currency_id', '512');
        config()->set('services.amwal.enabled', true);

        $this->createSchema();
        $this->seedPendingOrder();

        $this->hash = new AmwalSecureHash(self::KEY);
        $this->processor = new AmwalPaymentProcessor($this->hash);
        $this->app->instance(AmwalPaymentProcessor::class, $this->processor);
    }

    public function test_signed_success_marks_the_attempt_order_and_sales_payment_paid(): void
    {
        $result = $this->processor->processBrowserCallback($this->signedCallback(), 77);

        $this->assertTrue($result['paid']);
        $this->assertTrue($result['became_paid']);
        $this->assertSame('paid', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Status'));
        $this->assertSame('paid', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame('TXN-100', DB::table('Sales_Transactions_Details_T')->value('Card_Transaction_Id'));
        $this->assertSame('paid', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame(1, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_signed_gateway_response_wrapper_is_normalized_before_verification(): void
    {
        $callback = $this->signedCallback();
        $result = $this->processor->processBrowserCallback(
            $this->gatewayResponse($callback),
            77,
            10,
        );

        $this->assertTrue($result['paid']);
        $this->assertSame('00', $result['response_code']);
        $this->assertSame('paid', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame(1, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_signed_sdk_error_envelope_records_a_failed_attempt(): void
    {
        $callback = $this->signedCallback([
            'responseCode' => '05',
            'transactionId' => 'TXN-DECLINED',
            'transactionTime' => '2026-07-12T12:01:00Z',
        ]);
        $payload = [
            'callback' => 'errorCallback',
            'data' => $this->gatewayResponse($callback),
        ];

        $result = $this->processor->processBrowserCallback($payload, 77, 10);

        $this->assertFalse($result['paid']);
        $this->assertSame('failed', $result['attempt_status']);
        $this->assertSame('05', $result['response_code']);
        $this->assertSame('failed', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('failed', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame('05', DB::table('Sales_Transactions_Details_T')->value('Card_Error_Code'));
        $this->assertSame('failed', DB::table('Payment_Gateway_Events_T')->value('Outcome'));
    }

    public function test_conflicting_gateway_wrapper_is_rejected_without_state_change(): void
    {
        $callback = $this->signedCallback();
        $payload = $this->gatewayResponse($callback);
        $payload['data']['responseCode'] = '00';
        $payload['responseCode'] = '05';

        try {
            $this->processor->processBrowserCallback($payload, 77, 10);
            $this->fail('A conflicting callback wrapper should have been rejected.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('pending', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame(0, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_tampered_gateway_wrapper_is_rejected_without_state_change(): void
    {
        $callback = $this->signedCallback();
        $payload = $this->gatewayResponse($callback);
        $payload['data']['amount'] = '12.501';

        try {
            $this->processor->processBrowserCallback($payload, 77, 10);
            $this->fail('A tampered callback wrapper should have been rejected.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(401, $exception->status);
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('pending', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame(0, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_duplicate_callback_is_idempotent(): void
    {
        $payload = $this->signedCallback();
        $first = $this->processor->processBrowserCallback($payload, 77);
        $second = $this->processor->processBrowserCallback($payload, 77);

        $this->assertTrue($first['became_paid']);
        $this->assertFalse($second['became_paid']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame(1, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_tampered_callback_fails_without_changing_payment_state(): void
    {
        $payload = $this->signedCallback();
        $payload['amount'] = '12.501';

        try {
            $this->processor->processBrowserCallback($payload, 77);
            $this->fail('A tampered callback should have been rejected.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(401, $exception->status);
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame(0, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_browser_callback_must_match_the_expected_order(): void
    {
        try {
            $this->processor->processBrowserCallback($this->signedCallback(), 77, 999);
            $this->fail('A callback for a different order should have been rejected.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame(0, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_failure_after_paid_cannot_downgrade_the_order(): void
    {
        $this->processor->processBrowserCallback($this->signedCallback(), 77);

        $failure = $this->signedCallback([
            'responseCode' => '05',
            'transactionTime' => '2026-07-12T12:01:00Z',
        ]);
        $result = $this->processor->processBrowserCallback($failure, 77);

        $this->assertTrue($result['paid']);
        $this->assertSame('paid', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('paid', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
    }

    public function test_second_success_is_flagged_for_reconciliation_and_cannot_be_downgraded(): void
    {
        $this->processor->processBrowserCallback($this->signedCallback(), 77);
        $this->createNotificationsSchema();

        DB::table('Payment_Gateway_Attempts_T')->insert([
            'id' => 31,
            'Orders_Placed_Id' => 10,
            'Sales_Transactions_Details_Id' => 20,
            'Gateway' => 'amwal_smartbox',
            'Merchant_Reference' => 'ORD-TEST-ATTEMPT-2',
            'Amount' => 12.500,
            'Currency' => 'OMR',
            'Currency_Id' => '512',
            'Status' => 'pending',
            'Initiated_At' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondSuccess = $this->signedCallback([
            'merchantReference' => 'ORD-TEST-ATTEMPT-2',
            'transactionId' => 'TXN-200',
            'transactionTime' => '2026-07-12T12:02:00Z',
        ]);
        $result = $this->processor->processBrowserCallback($secondSuccess, 77);

        $this->assertFalse($result['paid']);
        $this->assertTrue($result['requires_review']);
        $this->assertSame('paid_requires_review', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('paid_requires_review', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame('paid_requires_review', DB::table('Payment_Gateway_Attempts_T')->where('id', 31)->value('Status'));
        $this->assertSame('TXN-100', DB::table('Sales_Transactions_Details_T')->value('Card_Transaction_Id'));
        $notification = DB::table('Conx_Notifications_T')->first();
        $this->assertNotNull($notification);
        $this->assertSame('App\\Notifications\\AmwalPaymentReconciliationRequired', $notification->type);
        $this->assertSame('duplicate_capture', json_decode((string) $notification->data, true)['reason']);

        $laterFailure = $this->signedCallback([
            'merchantReference' => 'ORD-TEST-ATTEMPT-2',
            'transactionId' => 'TXN-200',
            'responseCode' => '05',
            'transactionTime' => '2026-07-12T12:03:00Z',
        ]);
        $this->processor->processBrowserCallback($laterFailure, 77);

        $this->assertSame('paid_requires_review', DB::table('Payment_Gateway_Attempts_T')->where('id', 31)->value('Status'));
    }

    public function test_signed_callback_requires_the_customer_serialization_row(): void
    {
        DB::table('Customers_Master_T')->where('id', 77)->delete();

        try {
            $this->processor->processBrowserCallback($this->signedCallback(), 77);
            $this->fail('Settlement must fail closed when its customer serialization row is unavailable.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(404, $exception->status);
        }

        $this->assertSame('pending', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('pending', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame(0, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_late_success_for_cancelled_order_is_held_for_review(): void
    {
        $this->createNotificationsSchema();
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Status' => 'cancelled']);

        $result = $this->processor->processBrowserCallback($this->signedCallback(), 77, 10);

        $this->assertFalse($result['paid']);
        $this->assertTrue($result['requires_review']);
        $this->assertSame('paid_requires_review', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('paid_requires_review', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame('paid_requires_review', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame(0, DB::table('Customers_Loyalty_Transactions_T')->count());

        $notification = DB::table('Conx_Notifications_T')->first();
        $this->assertNotNull($notification);
        $this->assertSame('App\\Notifications\\AmwalPaymentReconciliationRequired', $notification->type);
        $notificationData = json_decode((string) $notification->data, true);
        $this->assertSame(10, $notificationData['order_id']);
        $this->assertSame('TXN-100', $notificationData['gateway_transaction_id']);
        $this->assertSame('critical', $notificationData['severity']);

        $duplicate = $this->signedCallback([
            'transactionTime' => '2026-07-12T12:04:00Z',
        ]);
        $this->processor->processBrowserCallback($duplicate, 77, 10);

        $this->assertSame(1, DB::table('Conx_Notifications_T')->count());
    }

    public function test_review_capture_cannot_be_reprocessed_or_downgraded_after_order_is_reopened(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Status' => 'cancelled']);
        $this->processor->processBrowserCallback($this->signedCallback(), 77, 10);

        // Simulate an operator restoring the business status while the captured
        // payment is still awaiting reconciliation.
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Status' => 'pending']);

        $duplicate = $this->signedCallback([
            'transactionTime' => '2026-07-12T12:04:00Z',
        ]);
        $duplicateResult = $this->processor->processBrowserCallback($duplicate, 77, 10);

        $this->assertFalse($duplicateResult['paid']);
        $this->assertTrue($duplicateResult['requires_review']);
        $this->assertSame('paid_requires_review', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('paid_requires_review', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame(0, DB::table('Customers_Loyalty_Transactions_T')->count());

        $failure = $this->signedCallback([
            'responseCode' => '05',
            'transactionTime' => '2026-07-12T12:05:00Z',
        ]);
        $this->processor->processBrowserCallback($failure, 77, 10);

        $this->assertSame('paid_requires_review', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('paid_requires_review', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
    }

    public function test_review_capture_cannot_be_overwritten_by_a_different_transaction_reference(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update(['Status' => 'cancelled']);
        $this->processor->processBrowserCallback($this->signedCallback(), 77, 10);

        try {
            $this->processor->processBrowserCallback($this->signedCallback([
                'transactionId' => 'TXN-DIFFERENT',
                'transactionTime' => '2026-07-12T12:04:00Z',
            ]), 77, 10);
            $this->fail('A captured attempt must retain its original gateway transaction reference.');
        } catch (AmwalPaymentException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame(
            'TXN-100',
            DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->value('Gateway_Transaction_Id'),
        );
        $this->assertSame(1, DB::table('Payment_Gateway_Events_T')->count());
    }

    public function test_signed_failure_after_local_cancellation_preserves_cancelled_state(): void
    {
        DB::table('Orders_Placed_T')->where('id', 10)->update([
            'Status' => 'cancelled',
            'Payment_Status' => 'cancelled',
        ]);
        DB::table('Sales_Transactions_Details_T')->where('id', 20)->update([
            'Payment_Status' => 'cancelled',
        ]);
        DB::table('Payment_Gateway_Attempts_T')->where('id', 30)->update([
            'Status' => 'cancelled',
        ]);

        $result = $this->processor->processBrowserCallback($this->signedCallback([
            'responseCode' => '05',
            'transactionId' => '',
            'transactionTime' => '2026-07-12T12:06:00Z',
        ]), 77, 10);

        $this->assertFalse($result['paid']);
        $this->assertSame('cancelled', DB::table('Orders_Placed_T')->value('Payment_Status'));
        $this->assertSame('cancelled', DB::table('Sales_Transactions_Details_T')->value('Payment_Status'));
        $this->assertSame('cancelled', DB::table('Payment_Gateway_Attempts_T')->value('Status'));
        $this->assertSame('ignored_after_cancellation', DB::table('Payment_Gateway_Events_T')->value('Outcome'));
    }

    public function test_cloud_notification_preserves_decimal_json_text_and_returns_required_acknowledgement(): void
    {
        // Disabling new checkout initiation must not strand in-flight callbacks.
        config()->set('services.amwal.enabled', false);

        $payload = [
            'MerchantId' => self::MERCHANT_ID,
            'TerminalId' => self::TERMINAL_ID,
            'AuthorizationDateTime' => '20260712120000',
            'DateTimeLocalTrxn' => '20260712160000',
            'ResponseCode' => '00',
            'TxnType' => 'Purchase',
            'PaidThrough' => 'Card',
            'SystemReference' => 'TXN-CLOUD-100',
            'Message' => 'Approved',
            'MerchantReference' => 'ORD-TEST-ATTEMPT',
            'Amount' => '12.500',
            'CurrencyId' => '512',
        ];
        $payload['SecureHash'] = $this->hash->cloudNotificationHash($payload);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $json = str_replace('"Amount":"12.500"', '"Amount":12.500', $json);
        $request = Request::create(
            '/api/payments/amwal/notification',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );

        $response = (new AmwalNotificationController())($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'success', 'success' => true], $response->getData(true));
        $this->assertSame('paid', DB::table('Orders_Placed_T')->value('Payment_Status'));
    }

    /** @param array<string, string> $overrides */
    private function signedCallback(array $overrides = []): array
    {
        $payload = array_merge([
            'amount' => '12.500',
            'currencyId' => '512',
            'customerId' => '',
            'customerTokenId' => '',
            'merchantId' => self::MERCHANT_ID,
            'merchantReference' => 'ORD-TEST-ATTEMPT',
            'responseCode' => '00',
            'terminalId' => self::TERMINAL_ID,
            'transactionId' => 'TXN-100',
            'transactionTime' => '2026-07-12T12:00:00Z',
        ], $overrides);
        $payload['secureHashValue'] = $this->hash->callbackHash($payload);

        return $payload;
    }

    /**
     * Reproduces the response object passed to SmartBox completeCallback and
     * errorCallback. The signed fields are nested; responseCode remains on the
     * APG wrapper.
     *
     * @param array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function gatewayResponse(array $callback): array
    {
        $responseCode = (string) ($callback['responseCode'] ?? '');
        unset($callback['responseCode']);

        return [
            'success' => $responseCode === '00',
            'responseCode' => $responseCode,
            'message' => null,
            'data' => $callback + [
                'hostResponseData' => [
                    'SessionId' => 'must-not-be-persisted',
                    'AccessUrl' => 'https://gateway.invalid/internal-session',
                ],
            ],
            'errorList' => [],
        ];
    }

    private function seedPendingOrder(): void
    {
        DB::table('Customers_Master_T')->insert(['id' => 77]);
        DB::table('Orders_Placed_T')->insert([
            'id' => 10,
            'Order_Code' => 'ORD-TEST',
            'Customers_Id' => 77,
            'Total_Price' => 12.500,
            'Payment_Method' => 'card',
            'Payment_Status' => 'pending',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Sales_Transactions_Details_T')->insert([
            'id' => 20,
            'Payment_Status' => 'pending',
            'Payment_Metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Payment_Gateway_Attempts_T')->insert([
            'id' => 30,
            'Orders_Placed_Id' => 10,
            'Sales_Transactions_Details_Id' => 20,
            'Gateway' => 'amwal_smartbox',
            'Merchant_Reference' => 'ORD-TEST-ATTEMPT',
            'Amount' => 12.500,
            'Currency' => 'OMR',
            'Currency_Id' => '512',
            'Status' => 'pending',
            'Initiated_At' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Orders_Placed_Details_T')->insert([
            'Orders_Placed_Id' => 10,
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('Orders_Placed_Vendors_T')->insert([
            'Orders_Placed_Id' => 10,
            'Commission_Type' => 'auto',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'Conx_Notifications_T',
            'Payment_Gateway_Events_T',
            'Payment_Gateway_Attempts_T',
            'Orders_Placed_Vendors_T',
            'Orders_Placed_Details_T',
            'Sales_Transactions_Details_T',
            'Customers_Loyalty_Transactions_T',
            'Customers_Loyalty_T',
            'System_Parameter_Loyalty_Points_T',
            'Customers_Master_T',
            'Orders_Placed_T',
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
        Schema::create('Sales_Transactions_Details_T', function (Blueprint $table) {
            $table->id();
            $table->string('Payment_Status');
            $table->string('Payment_Gateway')->nullable();
            $table->string('Card_Gateway')->nullable();
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
            $table->string('Currency_Id');
            $table->string('Status');
            $table->string('Gateway_Transaction_Id')->nullable()->unique();
            $table->string('Response_Code')->nullable();
            $table->string('Response_Message')->nullable();
            $table->string('Paid_Through')->nullable();
            $table->dateTime('Initiated_At');
            $table->dateTime('Completed_At')->nullable();
            $table->dateTime('Last_Notification_At')->nullable();
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
        Schema::create('Orders_Placed_Details_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->string('Status');
            $table->timestamps();
        });
        Schema::create('Orders_Placed_Vendors_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->string('Commission_Type')->nullable();
            $table->string('Status');
            $table->timestamps();
        });
        Schema::create('System_Parameter_Loyalty_Points_T', function (Blueprint $table) {
            $table->id();
            $table->decimal('Point', 18, 3)->nullable();
            $table->decimal('Earn_Amount', 18, 3)->nullable();
            $table->decimal('Earn_Points', 18, 3)->nullable();
        });
        Schema::create('Customers_Loyalty_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Customer_Id');
            $table->integer('Points_Earned')->default(0);
            $table->timestamps();
        });
        Schema::create('Customers_Loyalty_Transactions_T', function (Blueprint $table) {
            $table->id();
            $table->string('Loyalty_Transaction_Code');
            $table->unsignedBigInteger('Customer_Id');
            $table->unsignedBigInteger('Orders_Placed_Id');
            $table->integer('Points_Earned')->default(0);
            $table->integer('Points_Redeemed')->default(0);
            $table->decimal('Redeemed_Amount', 18, 3)->default(0);
            $table->timestamps();
        });
    }

    private function createNotificationsSchema(): void
    {
        Schema::create('Conx_Notifications_T', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
    }
}
