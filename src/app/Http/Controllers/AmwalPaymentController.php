<?php

namespace App\Http\Controllers;

use App\Services\Orders\CustomerUnpaidAmwalOrderCancellationService;
use App\Services\Checkout\ActiveAmwalCheckoutGuard;
use App\Services\Payments\Amwal\AmwalPaymentAttemptEnvironment;
use App\Services\Payments\Amwal\AmwalPaymentException;
use App\Services\Payments\Amwal\AmwalPaymentProcessor;
use App\Services\Payments\Amwal\AmwalSmartBoxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AmwalPaymentController extends Controller
{
    public function configuration(Request $request, int $order)
    {
        $validated = $request->validate([
            'language' => ['nullable', 'in:en,ar'],
        ]);

        $this->assertConfigured();

        if (! config('services.amwal.enabled')) {
            throw new AmwalPaymentException('Card payments are temporarily unavailable.', 503);
        }

        $this->assertSmartBoxEnvironment();
        $smartBox = app(AmwalSmartBoxService::class);

        $customer = Auth::user()?->customers;
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $result = DB::transaction(function () use ($order, $customer) {
            $lockedCustomer = DB::table('Customers_Master_T')
                ->where('id', $customer->id)
                ->lockForUpdate()
                ->first(['id']);

            if (! $lockedCustomer) {
                throw new AmwalPaymentException('Customer not found.', 404);
            }

            $amwalGuard = app(ActiveAmwalCheckoutGuard::class);
            $reviewOrder = $amwalGuard->reconciliationOrder((int) $customer->id);

            if ($reviewOrder) {
                throw new AmwalPaymentException(
                    'A previous card capture requires reconciliation before another payment can be opened.',
                    409,
                );
            }

            $recentCancelledOrder = $amwalGuard->recentCancelledOrder(
                (int) $customer->id,
                $order,
            );

            if ($recentCancelledOrder) {
                $retryAfter = $amwalGuard->retryAfterSeconds($recentCancelledOrder);

                throw new AmwalPaymentException(
                    "The previous card payment is still reconciling. Please retry in {$retryAfter} seconds.",
                    409,
                );
            }

            $orderRow = DB::table('Orders_Placed_T')
                ->where('id', $order)
                ->where('Customers_Id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (! $orderRow || ($orderRow->Payment_Method ?? null) !== 'card') {
                throw new AmwalPaymentException('The card payment order is not available.', 404);
            }

            if (in_array(($orderRow->Payment_Status ?? null), ['paid', 'paid_requires_review'], true)) {
                return [
                    'order' => $orderRow,
                    'attempt' => null,
                    'already_paid' => true,
                ];
            }

            if (strtolower((string) ($orderRow->Status ?? '')) !== 'pending') {
                throw new AmwalPaymentException('This order is no longer eligible for card payment.', 409);
            }

            $salesDetail = $this->salesDetailForOrder($orderRow);
            if (! $salesDetail) {
                throw new AmwalPaymentException('The order payment record is missing.', 409);
            }

            if (strtolower((string) ($salesDetail->Payment_Gateway ?? '')) !== 'amwal_smartbox') {
                throw new AmwalPaymentException('This order was not created for AmwalPay.', 409);
            }

            $attempt = DB::table('Payment_Gateway_Attempts_T')
                ->where('Orders_Placed_Id', $orderRow->id)
                ->where('Gateway', 'amwal_smartbox')
                ->where('Status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $createdAttempt = false;
            $configurationRequestedAt = now();

            if ($attempt) {
                $attemptMetadata = json_decode((string) ($attempt->Metadata ?? ''), true);
                $attemptEnvironment = is_array($attemptMetadata)
                    ? ($attemptMetadata['environment'] ?? null)
                    : null;

                if (! AmwalPaymentAttemptEnvironment::matches(
                    is_string($attemptEnvironment) ? $attemptEnvironment : null,
                    (string) config('services.amwal.environment'),
                )) {
                    throw new AmwalPaymentException(
                        'This payment attempt belongs to a different AmwalPay environment and cannot be resumed.',
                        409,
                    );
                }

                $attemptMetadata = is_array($attemptMetadata) ? $attemptMetadata : [];
                $attemptMetadata['last_configuration_requested_at'] =
                    $configurationRequestedAt->toIso8601String();
                $encodedAttemptMetadata = json_encode($attemptMetadata, JSON_UNESCAPED_SLASHES);

                DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->update([
                    'Metadata' => $encodedAttemptMetadata,
                    'updated_at' => $configurationRequestedAt,
                ]);
                $attempt->Metadata = $encodedAttemptMetadata;
                $attempt->updated_at = $configurationRequestedAt;
            }

            if (! $attempt) {
                $recentAttempts = DB::table('Payment_Gateway_Attempts_T')
                    ->where('Orders_Placed_Id', $orderRow->id)
                    ->where('created_at', '>=', now()->subHour())
                    ->count();

                if ($recentAttempts >= 5) {
                    throw new AmwalPaymentException('Too many payment attempts. Please try again later.', 429);
                }

                $merchantReference = $this->newMerchantReference((string) $orderRow->Order_Code);
                $attemptId = DB::table('Payment_Gateway_Attempts_T')->insertGetId([
                    'Orders_Placed_Id' => $orderRow->id,
                    'Sales_Transactions_Details_Id' => $salesDetail->id,
                    'Gateway' => 'amwal_smartbox',
                    'Merchant_Reference' => $merchantReference,
                    'Amount' => number_format((float) $salesDetail->Payment_Amount, 3, '.', ''),
                    'Currency' => 'OMR',
                    'Currency_Id' => (string) config('services.amwal.currency_id', '512'),
                    'Status' => 'pending',
                    'Initiated_At' => now(),
                    'Metadata' => json_encode([
                        'environment' => (string) config('services.amwal.environment', 'uat'),
                        'last_configuration_requested_at' => $configurationRequestedAt->toIso8601String(),
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $configurationRequestedAt,
                    'updated_at' => $configurationRequestedAt,
                ]);

                $attempt = DB::table('Payment_Gateway_Attempts_T')->where('id', $attemptId)->first();
                $createdAttempt = true;
            }

            $metadata = json_decode((string) ($salesDetail->Payment_Metadata ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['environment'] = (string) config('services.amwal.environment', 'uat');
            $metadata['requires_customer_action'] = true;

            $salesUpdate = [
                'Payment_Status' => 'pending',
                'Payment_Gateway' => 'amwal_smartbox',
                'Payment_Intent_Id' => $attempt->Merchant_Reference,
                'Payment_Metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ];

            if ($createdAttempt) {
                $salesUpdate['Card_Transaction_Id'] = null;
                $salesUpdate['Card_Error_Code'] = null;
                $salesUpdate['Card_Error_Message'] = null;
            }

            DB::table('Sales_Transactions_Details_T')->where('id', $salesDetail->id)->update($salesUpdate);

            if (($orderRow->Payment_Status ?? null) !== 'pending') {
                DB::table('Orders_Placed_T')->where('id', $orderRow->id)->update([
                    'Payment_Status' => 'pending',
                    'updated_at' => now(),
                ]);
                $orderRow->Payment_Status = 'pending';
            }

            return [
                'order' => $orderRow,
                'attempt' => $attempt,
                'already_paid' => false,
            ];
        }, 3);

        if ($result['already_paid']) {
            return response()->json([
                'payment' => $this->paymentPayload($result['order'], null),
                'smartbox' => null,
            ]);
        }

        $requestDateTime = now('UTC')->format('Y-m-d\TH:i:s.v\Z');
        $smartBoxPayload = $smartBox->configuration(
            amount: $result['attempt']->Amount,
            merchantReference: $result['attempt']->Merchant_Reference,
            requestDateTime: $requestDateTime,
            language: $validated['language'] ?? 'en',
        );

        return response()->json([
            'payment' => $this->paymentPayload($result['order'], $result['attempt']),
            'smartbox' => $smartBoxPayload,
        ]);
    }

    public function status(int $order)
    {
        $this->assertConfigured();

        $customer = Auth::user()?->customers;
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $orderRow = DB::table('Orders_Placed_T')
            ->where('id', $order)
            ->where('Customers_Id', $customer->id)
            ->first();

        if (! $orderRow || ($orderRow->Payment_Method ?? null) !== 'card') {
            return response()->json(['message' => 'The card payment order is not available.'], 404);
        }

        $attempt = DB::table('Payment_Gateway_Attempts_T')
            ->where('Orders_Placed_Id', $orderRow->id)
            ->where('Gateway', 'amwal_smartbox')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'payment' => $this->paymentPayload($orderRow, $attempt),
        ]);
    }

    public function callback(Request $request, int $order)
    {
        $this->assertConfigured();

        $validated = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        $customer = Auth::user()?->customers;
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        try {
            $result = app(AmwalPaymentProcessor::class)
                ->processBrowserCallback($validated['payload'], (int) $customer->id, $order);
        } catch (AmwalPaymentException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json(['payment' => $result]);
    }

    public function cancel(Request $request, int $order)
    {
        $request->validate([
            'restore_cart' => ['sometimes', 'boolean'],
        ]);

        $user = Auth::user();
        $customer = $user?->customers;
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        try {
            $result = app(CustomerUnpaidAmwalOrderCancellationService::class)->cancel(
                orderId: $order,
                customerId: (int) $customer->id,
                customerUserId: (int) $user->id,
                actorName: $user->User_Name ?? $user->name ?? "Customer #{$customer->id}",
                restoreCart: true,
            );
        } catch (AmwalPaymentException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        $cancelledOrder = DB::table('Orders_Placed_T')
            ->where('id', $order)
            ->where('Customers_Id', $customer->id)
            ->first();

        $paymentStatus = strtolower((string) ($cancelledOrder->Payment_Status ?? 'cancelled'));
        $requiresReview = $paymentStatus === 'paid_requires_review';

        return response()->json([
            'message' => $requiresReview
                ? 'The payment requires review before checkout can continue.'
                : 'The card payment attempt was cancelled and the cart was restored.',
            'payment' => [
                'order_id' => $order,
                'order_code' => $cancelledOrder->Order_Code ?? null,
                'amount' => number_format((float) ($cancelledOrder->Total_Price ?? 0), 3, '.', ''),
                'currency' => 'OMR',
                'status' => $paymentStatus,
                'order_status' => strtolower((string) ($cancelledOrder->Status ?? 'cancelled')),
                'paid' => $paymentStatus === 'paid',
                'payable' => false,
                'requires_action' => false,
                'requires_review' => $requiresReview,
            ],
            'cancellation' => [
                'idempotent' => $result['idempotent'],
                'released_lines' => $result['released_lines'],
                'released_loyalty_points' => $result['released_loyalty_points'],
                'cart_restoration' => $result['cart_restoration'],
            ],
        ]);
    }

    private function assertConfigured(): void
    {
        foreach (['merchant_id', 'terminal_id', 'secure_key'] as $key) {
            if (trim((string) config("services.amwal.{$key}")) === '') {
                throw new AmwalPaymentException('Card payments are not configured.', 503);
            }
        }

        $secureKey = trim((string) config('services.amwal.secure_key'));
        if (strlen($secureKey) % 2 !== 0 || ! ctype_xdigit($secureKey)) {
            throw new AmwalPaymentException('Card payments are not configured.', 503);
        }

        if (! Schema::hasTable('Payment_Gateway_Attempts_T') || ! Schema::hasTable('Payment_Gateway_Events_T')) {
            throw new AmwalPaymentException('The payment database migration is pending.', 503);
        }

        if (! Schema::hasColumns('Orders_Placed_T', ['Payment_Status', 'Payment_Method'])
            || ! Schema::hasColumns('Sales_Transactions_Details_T', [
                'Payment_Status',
                'Payment_Gateway',
                'Payment_Intent_Id',
                'Payment_Metadata',
                'Card_Gateway',
                'Card_Transaction_Id',
                'Card_Error_Code',
                'Card_Error_Message',
            ])) {
            throw new AmwalPaymentException('The payment database migration is pending.', 503);
        }
    }

    private function assertSmartBoxEnvironment(): void
    {
        $environment = strtolower(trim((string) config('services.amwal.environment')));
        $scriptUrl = trim((string) config('services.amwal.smartbox_url'));
        $expected = match ($environment) {
            'uat' => 'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1',
            'production', 'prod' => 'https://checkout.amwalpg.com/js/SmartBox.js?v=1.1',
            default => null,
        };

        if ($expected === null || ! hash_equals($expected, $scriptUrl)) {
            throw new AmwalPaymentException('The AmwalPay environment configuration is invalid.', 503);
        }
    }

    private function salesDetailForOrder(object $order): ?object
    {
        $header = DB::table('Sales_Transaction_Header_T')
            ->where('Orders_Placed_Id', $order->id)
            ->orderByDesc('id')
            ->first();

        if (! $header) {
            return null;
        }

        return DB::table('Sales_Transactions_Details_T')
            ->where('Sales_Transaction_Header_Id', $header->id)
            ->orderByDesc('id')
            ->first();
    }

    private function newMerchantReference(string $orderCode): string
    {
        $prefix = Str::upper((string) preg_replace('/[^A-Za-z0-9_-]/', '', $orderCode));
        $prefix = Str::limit($prefix !== '' ? $prefix : 'ORDER', 90, '');

        do {
            $reference = $prefix.'-'.Str::upper(Str::random(16));
        } while (DB::table('Payment_Gateway_Attempts_T')->where('Merchant_Reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(object $order, ?object $attempt): array
    {
        $status = (string) ($order->Payment_Status ?? $attempt->Status ?? 'pending');
        $captured = in_array($status, ['paid', 'paid_requires_review'], true);
        $payable = ! $captured && strtolower((string) ($order->Status ?? '')) === 'pending';
        $attemptMetadata = json_decode((string) ($attempt->Metadata ?? ''), true);
        $attemptMetadata = is_array($attemptMetadata) ? $attemptMetadata : [];

        return [
            'order_id' => (int) $order->id,
            'order_code' => $order->Order_Code ?? null,
            'method' => $order->Payment_Method ?? 'card',
            'amount' => number_format((float) ($attempt->Amount ?? $order->Total_Price ?? 0), 3, '.', ''),
            'currency' => $attempt->Currency ?? 'OMR',
            'status' => $status,
            'paid' => $status === 'paid',
            'payable' => $payable,
            'requires_action' => $payable,
            'requires_review' => $status === 'paid_requires_review'
                || ($attempt->Status ?? null) === 'paid_requires_review',
            'attempt_status' => $attempt->Status ?? null,
            'response_code' => $attempt->Response_Code ?? null,
            'merchant_reference' => $attempt->Merchant_Reference ?? null,
            'cart_restoration' => is_array($attemptMetadata['cart_restoration'] ?? null)
                ? $attemptMetadata['cart_restoration']
                : null,
        ];
    }
}
