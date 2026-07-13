<?php

namespace App\Services\Payments\Amwal;

use App\Events\OrderCreated;
use App\Events\OrderPlaced;
use App\Helpers\CodeGenerator;
use App\Mail\NewOrderEmail;
use App\Models\ConxDatabaseNotification;
use App\Services\Notifications\CustomerNotificationService;
use App\Support\Notifications\CustomerNotificationPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

final class AmwalPaymentProcessor
{
    /** @var list<string> */
    private const BROWSER_CALLBACK_FIELDS = [
        'amount',
        'currencyId',
        'customerId',
        'customerTokenId',
        'merchantId',
        'merchantReference',
        'responseCode',
        'terminalId',
        'transactionId',
        'transactionTime',
    ];

    public function __construct(
        private readonly AmwalSecureHash $secureHash,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function processBrowserCallback(array $payload, int $customerId, ?int $expectedOrderId = null): array
    {
        $payload = $this->normalizeBrowserCallback($payload);

        if (!$this->secureHash->verifyCallback($payload)) {
            throw new AmwalPaymentException('The payment response could not be authenticated.', 401);
        }

        return $this->process(
            source: 'browser',
            canonical: $this->secureHash->canonicalCallback($payload),
            merchantReference: $this->value($payload, 'merchantReference'),
            merchantId: $this->value($payload, 'merchantId'),
            terminalId: $this->value($payload, 'terminalId'),
            currencyId: $this->value($payload, 'currencyId'),
            amount: $payload['amount'] ?? null,
            responseCode: $this->value($payload, 'responseCode'),
            transactionId: $this->value($payload, 'transactionId'),
            message: null,
            paidThrough: null,
            transactionType: 'Purchase',
            customerId: $customerId,
            expectedOrderId: $expectedOrderId,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function processCloudNotification(array $payload): array
    {
        if (!$this->secureHash->verifyCloudNotification($payload)) {
            throw new AmwalPaymentException('The notification could not be authenticated.', 401);
        }

        return $this->process(
            source: 'cloud',
            canonical: $this->secureHash->canonicalCloudNotification($payload),
            merchantReference: $this->value($payload, 'MerchantReference'),
            merchantId: $this->value($payload, 'MerchantId'),
            terminalId: $this->value($payload, 'TerminalId'),
            currencyId: $this->value($payload, 'CurrencyId'),
            amount: $payload['Amount'] ?? null,
            responseCode: $this->value($payload, 'ResponseCode'),
            transactionId: $this->value($payload, 'SystemReference'),
            message: $this->value($payload, 'Message'),
            paidThrough: $this->value($payload, 'PaidThrough'),
            transactionType: $this->value($payload, 'TxnType'),
            customerId: null,
            expectedOrderId: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function process(
        string $source,
        string $canonical,
        string $merchantReference,
        string $merchantId,
        string $terminalId,
        string $currencyId,
        mixed $amount,
        string $responseCode,
        string $transactionId,
        ?string $message,
        ?string $paidThrough,
        string $transactionType,
        ?int $customerId,
        ?int $expectedOrderId,
    ): array {
        $this->assertConfiguredIdentifiers($merchantId, $terminalId, $currencyId);

        if ($merchantReference === '') {
            throw new AmwalPaymentException('The payment reference is missing.');
        }

        $digest = hash('sha256', $source.'|'.$canonical);

        try {
            $result = DB::transaction(function () use (
                $source,
                $digest,
                $merchantReference,
                $amount,
                $responseCode,
                $transactionId,
                $message,
                $paidThrough,
                $transactionType,
                $customerId,
                $expectedOrderId,
            ) {
                $attemptIdentity = DB::table('Payment_Gateway_Attempts_T')
                    ->where('Gateway', 'amwal_smartbox')
                    ->where('Merchant_Reference', $merchantReference)
                    ->first(['id', 'Orders_Placed_Id']);

                if (!$attemptIdentity) {
                    throw new AmwalPaymentException('The payment reference is not recognized.', 404);
                }

                $orderIdentity = DB::table('Orders_Placed_T')
                    ->where('id', $attemptIdentity->Orders_Placed_Id)
                    ->first(['id', 'Customers_Id']);

                if (!$orderIdentity
                    || ($customerId !== null && (int) $orderIdentity->Customers_Id !== $customerId)) {
                    throw new AmwalPaymentException('The payment order is not available.', 404);
                }

                // Checkout creation, cart mutation and cancellation all use the
                // customer row as their serialization point. Settlement must
                // take that same lock before the order so a late signed capture
                // cannot cross a new checkout's reconciliation checks.
                $lockedCustomer = DB::table('Customers_Master_T')
                    ->where('id', $orderIdentity->Customers_Id)
                    ->lockForUpdate()
                    ->first(['id']);

                if (!$lockedCustomer) {
                    throw new AmwalPaymentException('The payment order is not available.', 404);
                }

                $order = DB::table('Orders_Placed_T')
                    ->where('id', $attemptIdentity->Orders_Placed_Id)
                    ->where('Customers_Id', $lockedCustomer->id)
                    ->lockForUpdate()
                    ->first();

                $attempt = DB::table('Payment_Gateway_Attempts_T')
                    ->where('id', $attemptIdentity->id)
                    ->where('Orders_Placed_Id', $attemptIdentity->Orders_Placed_Id)
                    ->where('Gateway', 'amwal_smartbox')
                    ->where('Merchant_Reference', $merchantReference)
                    ->lockForUpdate()
                    ->first();

                if (!$attempt) {
                    throw new AmwalPaymentException('The payment reference is not recognized.', 404);
                }

                if (!$order || ($customerId !== null && (int) $order->Customers_Id !== $customerId)) {
                    throw new AmwalPaymentException('The payment order is not available.', 404);
                }

                if ($expectedOrderId !== null && (int) $order->id !== $expectedOrderId) {
                    throw new AmwalPaymentException('The payment response belongs to a different order.', 409);
                }

                $incomingUnits = $this->moneyToUnits($amount);
                $expectedUnits = $this->moneyToUnits($attempt->Amount);

                if ($incomingUnits === null || $expectedUnits === null || $incomingUnits !== $expectedUnits) {
                    throw new AmwalPaymentException('The payment amount does not match the order.');
                }

                $existingEvent = DB::table('Payment_Gateway_Events_T')
                    ->where('Payload_Digest', $digest)
                    ->first();

                if ($existingEvent) {
                    return $this->result($order, $attempt, false, true);
                }

                if (strcasecmp($transactionType, 'Purchase') !== 0) {
                    $this->recordEvent($attempt, $source, $digest, $transactionId, $responseCode, 'ignored');

                    return $this->result($order, $attempt, false, false);
                }

                $isSuccess = $responseCode === '00';

                if ($isSuccess && $transactionId === '') {
                    throw new AmwalPaymentException('The gateway transaction reference is missing.');
                }

                if ($isSuccess) {
                    $otherAttempt = DB::table('Payment_Gateway_Attempts_T')
                        ->where('Gateway_Transaction_Id', $transactionId)
                        ->where('id', '<>', $attempt->id)
                        ->exists();

                    if ($otherAttempt) {
                        throw new AmwalPaymentException('The gateway transaction is already linked to another order.', 409);
                    }

                    if (in_array(($attempt->Status ?? null), ['paid', 'paid_requires_review'], true)
                        && !empty($attempt->Gateway_Transaction_Id)
                        && $attempt->Gateway_Transaction_Id !== $transactionId) {
                        throw new AmwalPaymentException('The paid transaction reference does not match.', 409);
                    }

                    if (strtolower((string) ($order->Status ?? '')) !== 'pending'
                        && ($order->Payment_Status ?? null) !== 'paid') {
                        DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->update([
                            'Status' => 'paid_requires_review',
                            'Gateway_Transaction_Id' => $transactionId,
                            'Response_Code' => $responseCode,
                            'Response_Message' => $this->safeText($message, 500),
                            'Paid_Through' => $this->safeText($paidThrough, 50),
                            'Completed_At' => now(),
                            'Last_Notification_At' => $source === 'cloud' ? now() : $attempt->Last_Notification_At,
                            'updated_at' => now(),
                        ]);
                        DB::table('Orders_Placed_T')->where('id', $order->id)->update([
                            'Payment_Status' => 'paid_requires_review',
                            'updated_at' => now(),
                        ]);
                        $this->updateSalesPayment(
                            salesDetailId: (int) $attempt->Sales_Transactions_Details_Id,
                            status: 'paid_requires_review',
                            transactionId: $transactionId,
                            responseCode: null,
                            message: null,
                            source: $source,
                        );
                        $this->recordEvent(
                            $attempt,
                            $source,
                            $digest,
                            $transactionId,
                            $responseCode,
                            'paid_nonpayable_order',
                        );
                        $this->recordReconciliationNotification(
                            order: $order,
                            attempt: $attempt,
                            transactionId: $transactionId,
                            reason: 'late_capture_nonpayable',
                        );

                        Log::critical('AmwalPay payment received for a non-payable order.', [
                            'order_id' => (int) $order->id,
                            'attempt_id' => (int) $attempt->id,
                            'order_status' => (string) ($order->Status ?? ''),
                        ]);

                        $freshAttempt = DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->first();
                        $freshOrder = DB::table('Orders_Placed_T')->where('id', $order->id)->first();

                        return $this->result($freshOrder, $freshAttempt, false, false);
                    }

                    $otherPaidAttempt = DB::table('Payment_Gateway_Attempts_T')
                        ->where('Orders_Placed_Id', $order->id)
                        ->where('id', '<>', $attempt->id)
                        ->whereIn('Status', ['paid', 'paid_requires_review'])
                        ->lockForUpdate()
                        ->first();

                    if ($otherPaidAttempt) {
                        if (!empty($attempt->Gateway_Transaction_Id)
                            && $attempt->Gateway_Transaction_Id !== $transactionId) {
                            throw new AmwalPaymentException('The duplicate payment reference does not match.', 409);
                        }

                        DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->update([
                            'Status' => 'paid_requires_review',
                            'Gateway_Transaction_Id' => $transactionId,
                            'Response_Code' => $responseCode,
                            'Response_Message' => $this->safeText($message, 500),
                            'Paid_Through' => $this->safeText($paidThrough, 50),
                            'Completed_At' => now(),
                            'Last_Notification_At' => $source === 'cloud' ? now() : $attempt->Last_Notification_At,
                            'updated_at' => now(),
                        ]);

                        DB::table('Orders_Placed_T')->where('id', $order->id)->update([
                            'Payment_Status' => 'paid_requires_review',
                            'updated_at' => now(),
                        ]);

                        $this->markSalesPaymentRequiresReview(
                            salesDetailId: (int) $attempt->Sales_Transactions_Details_Id,
                            source: $source,
                        );

                        $this->recordEvent(
                            $attempt,
                            $source,
                            $digest,
                            $transactionId,
                            $responseCode,
                            'duplicate_payment',
                        );
                        $this->recordReconciliationNotification(
                            order: $order,
                            attempt: $attempt,
                            transactionId: $transactionId,
                            reason: 'duplicate_capture',
                        );

                        Log::critical('AmwalPay duplicate payment requires reconciliation.', [
                            'order_id' => (int) $order->id,
                            'attempt_id' => (int) $attempt->id,
                            'original_attempt_id' => (int) $otherPaidAttempt->id,
                        ]);

                        $freshAttempt = DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->first();
                        $freshOrder = DB::table('Orders_Placed_T')->where('id', $order->id)->first();

                        return $this->result($freshOrder, $freshAttempt, false, false);
                    }

                    if (in_array(($attempt->Status ?? null), ['paid', 'paid_requires_review'], true)) {
                        if (($attempt->Gateway_Transaction_Id ?? null) !== $transactionId) {
                            throw new AmwalPaymentException('The paid transaction reference does not match.', 409);
                        }

                        $outcome = ($attempt->Status ?? null) === 'paid'
                            ? 'duplicate_paid'
                            : 'duplicate_paid_requires_review';
                        $this->recordEvent($attempt, $source, $digest, $transactionId, $responseCode, $outcome);

                        return $this->result($order, $attempt, false, false);
                    }

                    $becamePaid = !in_array(($order->Payment_Status ?? null), ['paid', 'paid_requires_review'], true);

                    DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->update([
                        'Status' => 'paid',
                        'Gateway_Transaction_Id' => $transactionId,
                        'Response_Code' => $responseCode,
                        'Response_Message' => $this->safeText($message, 500),
                        'Paid_Through' => $this->safeText($paidThrough, 50),
                        'Completed_At' => now(),
                        'Last_Notification_At' => $source === 'cloud' ? now() : $attempt->Last_Notification_At,
                        'updated_at' => now(),
                    ]);

                    DB::table('Orders_Placed_T')->where('id', $order->id)->update([
                        'Payment_Status' => 'paid',
                        'updated_at' => now(),
                    ]);

                    $this->updateSalesPayment(
                        salesDetailId: (int) $attempt->Sales_Transactions_Details_Id,
                        status: 'paid',
                        transactionId: $transactionId,
                        responseCode: null,
                        message: null,
                        source: $source,
                    );

                    if ($becamePaid) {
                        $this->applyDeferredLoyaltyEarn($order);
                    }

                    $this->recordEvent($attempt, $source, $digest, $transactionId, $responseCode, 'paid');

                    $freshAttempt = DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->first();
                    $freshOrder = DB::table('Orders_Placed_T')->where('id', $order->id)->first();

                    return $this->result($freshOrder, $freshAttempt, $becamePaid, false);
                }

                if (($attempt->Status ?? null) === 'cancelled'
                    || ($order->Payment_Status ?? null) === 'cancelled') {
                    $this->recordEvent(
                        $attempt,
                        $source,
                        $digest,
                        $transactionId,
                        $responseCode,
                        'ignored_after_cancellation',
                    );

                    return $this->result($order, $attempt, false, false);
                }

                if (!in_array(($attempt->Status ?? null), ['paid', 'paid_requires_review'], true)) {
                    DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->update([
                        'Status' => 'failed',
                        'Gateway_Transaction_Id' => $transactionId !== '' ? $transactionId : null,
                        'Response_Code' => $responseCode,
                        'Response_Message' => $this->safeText($message, 500),
                        'Paid_Through' => $this->safeText($paidThrough, 50),
                        'Completed_At' => now(),
                        'Last_Notification_At' => $source === 'cloud' ? now() : $attempt->Last_Notification_At,
                        'updated_at' => now(),
                    ]);

                    $latestAttemptId = (int) DB::table('Payment_Gateway_Attempts_T')
                        ->where('Orders_Placed_Id', $order->id)
                        ->max('id');

                    if (!in_array(($order->Payment_Status ?? null), ['paid', 'paid_requires_review'], true)
                        && $latestAttemptId === (int) $attempt->id) {
                        DB::table('Orders_Placed_T')->where('id', $order->id)->update([
                            'Payment_Status' => 'failed',
                            'updated_at' => now(),
                        ]);

                        $this->updateSalesPayment(
                            salesDetailId: (int) $attempt->Sales_Transactions_Details_Id,
                            status: 'failed',
                            transactionId: $transactionId !== '' ? $transactionId : null,
                            responseCode: $responseCode,
                            message: $message,
                            source: $source,
                        );
                    }
                }

                $this->recordEvent($attempt, $source, $digest, $transactionId, $responseCode, 'failed');

                $freshAttempt = DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->first();
                $freshOrder = DB::table('Orders_Placed_T')->where('id', $order->id)->first();

                return $this->result($freshOrder, $freshAttempt, false, false);
            }, 3);

            if (!empty($result['became_paid'])) {
                $this->notifyVendorsOfPaidOrder((int) $result['order_id']);
                $this->notifyStakeholdersOfPaidOrder((int) $result['order_id']);
            }

            return $result;
        } catch (QueryException $exception) {
            if (!$this->isDuplicateEvent($exception)) {
                throw $exception;
            }

            $event = DB::table('Payment_Gateway_Events_T')
                ->where('Payload_Digest', $digest)
                ->first();

            if (!$event) {
                throw $exception;
            }

            $attempt = DB::table('Payment_Gateway_Attempts_T')
                ->where('id', $event->Payment_Gateway_Attempt_Id)
                ->first();
            $order = $attempt
                ? DB::table('Orders_Placed_T')->where('id', $attempt->Orders_Placed_Id)->first()
                : null;

            if (!$attempt || !$order) {
                throw $exception;
            }

            return $this->result($order, $attempt, false, true);
        }
    }

    private function assertConfiguredIdentifiers(string $merchantId, string $terminalId, string $currencyId): void
    {
        if (!hash_equals((string) config('services.amwal.merchant_id'), $merchantId)
            || !hash_equals((string) config('services.amwal.terminal_id'), $terminalId)
            || !hash_equals((string) config('services.amwal.currency_id', '512'), $currencyId)) {
            throw new AmwalPaymentException('The payment response identifiers do not match.', 422);
        }
    }

    /**
     * SmartBox forwards the APG response wrapper to the configured callback. The
     * documented signed callback fields live in its nested data object, while
     * responseCode lives on the response wrapper. Accept the documented flat
     * form as well, and fail closed when duplicate layers disagree.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeBrowserCallback(array $payload): array
    {
        $response = $payload;

        if (array_key_exists('callback', $payload)) {
            $callback = $payload['callback'];
            if (!is_string($callback)
                || !in_array($callback, ['completeCallback', 'errorCallback'], true)
                || !isset($payload['data'])
                || !is_array($payload['data'])) {
                throw new AmwalPaymentException('The payment response envelope is invalid.', 422);
            }

            $response = $payload['data'];
        }

        $isGatewayWrapper = array_key_exists('success', $response)
            || array_key_exists('errorList', $response)
            || (array_key_exists('responseCode', $response) && array_key_exists('data', $response));

        if ($isGatewayWrapper && (!isset($response['data']) || !is_array($response['data']))) {
            throw new AmwalPaymentException('The payment response envelope is invalid.', 422);
        }

        $details = $isGatewayWrapper ? $response['data'] : $response;
        $normalized = [];

        foreach (self::BROWSER_CALLBACK_FIELDS as $field) {
            $hasDetailsValue = array_key_exists($field, $details);
            $hasResponseValue = $details !== $response && array_key_exists($field, $response);

            if ($hasDetailsValue && $hasResponseValue
                && !$this->sameCallbackValue($details[$field], $response[$field])) {
                throw new AmwalPaymentException('The payment response contains conflicting values.', 422);
            }

            if ($hasDetailsValue) {
                $normalized[$field] = $details[$field];
            } elseif ($hasResponseValue) {
                $normalized[$field] = $response[$field];
            }
        }

        [$detailsHasHash, $detailsHash] = $this->callbackHashValue($details);
        [$responseHasHash, $responseHash] = $details !== $response
            ? $this->callbackHashValue($response)
            : [false, null];

        if ($detailsHasHash && $responseHasHash
            && !$this->sameHashValue($detailsHash, $responseHash)) {
            throw new AmwalPaymentException('The payment response contains conflicting hashes.', 401);
        }

        if ($detailsHasHash || $responseHasHash) {
            $normalized['secureHashValue'] = $detailsHasHash ? $detailsHash : $responseHash;
        }

        return $normalized;
    }

    private function sameCallbackValue(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if ((!is_string($left) && !is_int($left) && !is_float($left))
            || (!is_string($right) && !is_int($right) && !is_float($right))) {
            return $left === $right;
        }

        if ((is_float($left) && !is_finite($left)) || (is_float($right) && !is_finite($right))) {
            return false;
        }

        return (string) $left === (string) $right;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: bool, 1: mixed}
     */
    private function callbackHashValue(array $payload): array
    {
        $found = false;
        $hash = null;

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (!in_array($normalizedKey, ['securehash', 'securehashvalue'], true)) {
                continue;
            }

            if ($found && !$this->sameHashValue($hash, $value)) {
                throw new AmwalPaymentException('The payment response contains conflicting hashes.', 401);
            }

            $found = true;
            $hash = $value;
        }

        return [$found, $hash];
    }

    private function sameHashValue(mixed $left, mixed $right): bool
    {
        return is_string($left)
            && is_string($right)
            && hash_equals(strtoupper($left), strtoupper($right));
    }

    private function notifyVendorsOfPaidOrder(int $orderId): void
    {
        if (!Schema::hasTable('Conx_Notifications_T')) {
            return;
        }

        $vendorOrders = DB::table('Orders_Placed_Vendors_T')
            ->where('Orders_Placed_Id', $orderId)
            ->get(['Vendor_Id', 'Vendor_Order_Code', 'Total']);

        foreach ($vendorOrders as $vendorOrder) {
            try {
                ConxDatabaseNotification::create([
                    'type' => 'App\\Notifications\\VendorNewSale',
                    'notifiable_type' => 'App\\Models\\Vendor',
                    'notifiable_id' => $vendorOrder->Vendor_Id,
                    'data' => [
                        'title' => 'New paid sale',
                        'message' => 'Payment is confirmed for order '
                            .$vendorOrder->Vendor_Order_Code.' totalling '
                            .number_format((float) $vendorOrder->Total, 3).'.',
                        'url' => '/orders',
                    ],
                ]);
            } catch (\Throwable $exception) {
                Log::error('Failed to notify vendor of paid AmwalPay order.', [
                    'order_id' => $orderId,
                    'vendor_id' => (int) $vendorOrder->Vendor_Id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function notifyStakeholdersOfPaidOrder(int $orderId): void
    {
        $order = DB::table('Orders_Placed_T')->where('id', $orderId)->first([
            'id',
            'Order_Code',
            'Customers_Id',
            'Total_Price',
            'Status',
        ]);

        if (!$order) {
            return;
        }

        if (Schema::hasTable('Conx_Notifications_T')) {
            try {
                ConxDatabaseNotification::create([
                    'type' => 'App\\Notifications\\NewOrder',
                    'notifiable_type' => 'App\\Models\\Admin',
                    'notifiable_id' => 1,
                    'data' => [
                        'title' => 'New Paid Order Has Been Created',
                        'message' => 'Payment is confirmed for order '.$order->Order_Code.'.',
                        'order_id' => $orderId,
                        'url' => '/orders/'.$orderId,
                    ],
                ]);
            } catch (\Throwable $exception) {
                Log::error('Failed to create paid-order admin notification.', [
                    'order_id' => $orderId,
                    'error' => $exception->getMessage(),
                ]);
            }

            try {
                $customerUserId = Schema::hasColumn('Customers_Master_T', 'User_Id')
                    ? (int) DB::table('Customers_Master_T')
                        ->where('id', $order->Customers_Id)
                        ->value('User_Id')
                    : 0;

                if ($customerUserId > 0) {
                    app(CustomerNotificationService::class)->notifyUser(
                        $customerUserId,
                        'customer.order_update',
                        CustomerNotificationPayload::orderUpdate(
                            orderId: $orderId,
                            orderCode: (string) $order->Order_Code,
                            status: (string) ($order->Status ?? 'pending'),
                        ),
                    );
                }
            } catch (\Throwable $exception) {
                Log::error('Failed to create paid-order customer notification.', [
                    'order_id' => $orderId,
                    'customer_id' => (int) $order->Customers_Id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            event(new OrderPlaced($orderId, (string) $order->Order_Code, (float) $order->Total_Price));
            event(new OrderCreated($orderId, [
                'title' => 'New Order Placed',
                'message' => 'Order '.$order->Order_Code.' has been paid.',
                'order_id' => $orderId,
            ]));
            Mail::to('buzz644@yahoo.com')->queue(new NewOrderEmail((string) $order->Order_Code));
        } catch (\Throwable $exception) {
            Log::error('Paid-order events or email failed.', [
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function updateSalesPayment(
        int $salesDetailId,
        string $status,
        ?string $transactionId,
        ?string $responseCode,
        ?string $message,
        string $source,
    ): void {
        if ($salesDetailId <= 0) {
            throw new AmwalPaymentException('The order payment record is missing.', 409);
        }

        $row = DB::table('Sales_Transactions_Details_T')
            ->where('id', $salesDetailId)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            throw new AmwalPaymentException('The order payment record is missing.', 409);
        }

        $metadata = json_decode((string) ($row->Payment_Metadata ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['last_response_source'] = $source;
        $metadata['last_response_at'] = now()->toIso8601String();

        DB::table('Sales_Transactions_Details_T')->where('id', $salesDetailId)->update([
            'Payment_Status' => $status,
            'Payment_Gateway' => 'amwal_smartbox',
            'Card_Gateway' => 'AmwalPay',
            'Card_Transaction_Id' => $transactionId,
            'Card_Error_Code' => $responseCode,
            'Card_Error_Message' => $this->safeText($message, 500),
            'Payment_Metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function markSalesPaymentRequiresReview(int $salesDetailId, string $source): void
    {
        if ($salesDetailId <= 0) {
            throw new AmwalPaymentException('The order payment record is missing.', 409);
        }

        $row = DB::table('Sales_Transactions_Details_T')
            ->where('id', $salesDetailId)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            throw new AmwalPaymentException('The order payment record is missing.', 409);
        }

        $metadata = json_decode((string) ($row->Payment_Metadata ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['last_response_source'] = $source;
        $metadata['last_response_at'] = now()->toIso8601String();
        $metadata['reconciliation_reason'] = 'duplicate_capture';

        DB::table('Sales_Transactions_Details_T')->where('id', $salesDetailId)->update([
            'Payment_Status' => 'paid_requires_review',
            // Preserve the first verified Card_Transaction_Id. The second
            // capture remains attached to its gateway-attempt audit row.
            'Payment_Metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function applyDeferredLoyaltyEarn(object $order): void
    {
        $alreadyEarned = DB::table('Customers_Loyalty_Transactions_T')
            ->where('Orders_Placed_Id', $order->id)
            ->where('Points_Earned', '>', 0)
            ->exists();

        if ($alreadyEarned) {
            return;
        }

        $settings = DB::table('System_Parameter_Loyalty_Points_T')->first();
        if (!$settings) {
            return;
        }

        $earnAmount = (float) ($settings->Earn_Amount ?? 1);
        $earnPoints = (float) ($settings->Earn_Points ?? $settings->Point ?? 0);
        $pointsPerRial = $earnAmount > 0 ? $earnPoints / $earnAmount : 0;
        $pointsEarned = (int) round($pointsPerRial * (float) ($order->Total_Price ?? 0));

        if ($pointsEarned <= 0) {
            return;
        }

        DB::table('Customers_Loyalty_T')->where('Customer_Id', $order->Customers_Id)->update([
            'Points_Earned' => DB::raw('Points_Earned + '.(int) $pointsEarned),
            'updated_at' => now(),
        ]);

        DB::table('Customers_Loyalty_Transactions_T')->insert([
            'Loyalty_Transaction_Code' => CodeGenerator::createCode(
                'LOYTRANS',
                'Customers_Loyalty_Transactions_T',
                'Loyalty_Transaction_Code',
            ),
            'Customer_Id' => $order->Customers_Id,
            'Orders_Placed_Id' => $order->id,
            'Points_Earned' => $pointsEarned,
            'Points_Redeemed' => 0,
            'Redeemed_Amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recordEvent(
        object $attempt,
        string $source,
        string $digest,
        string $transactionId,
        string $responseCode,
        string $outcome,
    ): void {
        DB::table('Payment_Gateway_Events_T')->insert([
            'Payment_Gateway_Attempt_Id' => $attempt->id,
            'Orders_Placed_Id' => $attempt->Orders_Placed_Id,
            'Gateway' => 'amwal_smartbox',
            'Source' => $source,
            'Payload_Digest' => $digest,
            'Merchant_Reference' => $attempt->Merchant_Reference,
            'Gateway_Transaction_Id' => $transactionId !== '' ? $transactionId : null,
            'Response_Code' => $responseCode !== '' ? $responseCode : null,
            'Outcome' => $outcome,
            'Processed_At' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Persist one admin-visible reconciliation alert for a late capture. The
     * order row is locked by process(), so the explicit existence check is
     * serialized across browser and cloud callbacks. A deterministic UUID
     * also protects against accidental duplicates outside that lock order.
     */
    private function recordReconciliationNotification(
        object $order,
        object $attempt,
        string $transactionId,
        string $reason,
    ): void {
        if (! Schema::hasTable('Conx_Notifications_T')) {
            return;
        }

        $adminIds = Schema::hasTable('Secx_Admin_User_Master_T')
            ? DB::table('Secx_Admin_User_Master_T')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
            : collect();

        if ($adminIds->isEmpty()) {
            // Preserve the application's existing admin-notification fallback.
            $adminIds = collect([1]);
        }

        $message = $reason === 'duplicate_capture'
            ? 'A second card capture was received for order '
                .($order->Order_Code ?? '#'.$order->id)
                .'. Stop fulfillment and payout until the duplicate charge is reconciled.'
            : 'A card payment was captured after order '
                .($order->Order_Code ?? '#'.$order->id)
                .' became non-payable. Do not collect another card payment until it is reconciled.';

        $data = json_encode([
            'title' => 'AmwalPay reconciliation required',
            'message' => $message,
            'order_id' => (int) $order->id,
            'attempt_id' => (int) $attempt->id,
            'gateway_transaction_id' => $transactionId,
            'payment_status' => 'paid_requires_review',
            'reason' => $reason,
            'severity' => 'critical',
            'reconciliation_key' => 'amwal-late-capture-order-'.(int) $order->id
                .'-attempt-'.(int) $attempt->id,
            'url' => '/orders/'.(int) $order->id,
        ], JSON_UNESCAPED_SLASHES);

        foreach ($adminIds as $adminId) {
            $notificationId = $this->reconciliationNotificationId(
                (int) $order->id,
                (int) $attempt->id,
                $adminId,
            );

            if (DB::table('Conx_Notifications_T')->where('id', $notificationId)->exists()) {
                continue;
            }

            DB::table('Conx_Notifications_T')->insert([
                'id' => $notificationId,
                'type' => 'App\\Notifications\\AmwalPaymentReconciliationRequired',
                'notifiable_type' => 'App\\Models\\Admin',
                'notifiable_id' => $adminId,
                'data' => $data,
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function reconciliationNotificationId(int $orderId, int $attemptId, int $adminId): string
    {
        return '00000000-0000-5000-8000-'
            .substr(hash('sha256', "amwal-late-capture-order|{$orderId}|{$attemptId}|{$adminId}"), 0, 12);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(object $order, object $attempt, bool $becamePaid, bool $idempotent): array
    {
        $paymentStatus = (string) ($order->Payment_Status ?? $attempt->Status ?? 'pending');

        return [
            'order_id' => (int) $order->id,
            'order_code' => $order->Order_Code ?? null,
            'payment_status' => $paymentStatus,
            'attempt_status' => (string) ($attempt->Status ?? 'pending'),
            'response_code' => $attempt->Response_Code ?? null,
            'paid' => $paymentStatus === 'paid',
            'became_paid' => $becamePaid,
            'idempotent' => $idempotent,
            'requires_review' => $paymentStatus === 'paid_requires_review'
                || ($attempt->Status ?? null) === 'paid_requires_review',
        ];
    }

    private function moneyToUnits(mixed $amount): ?int
    {
        if (is_int($amount)) {
            return $amount >= 0 ? $amount * 1000 : null;
        }

        if (is_float($amount)) {
            if (!is_finite($amount) || $amount < 0) {
                return null;
            }

            $amount = number_format($amount, 3, '.', '');
        }

        $value = trim((string) $amount);
        if (!preg_match('/^(\d{1,15})(?:\.(\d{1,3}))?$/', $value, $matches)) {
            return null;
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 3, '0');

        return ($whole * 1000) + (int) $fraction;
    }

    /** @param array<string, mixed> $payload */
    private function value(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function safeText(?string $value, int $limit): ?string
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function isDuplicateEvent(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['2601', '2627', '1062'], true)
            || str_contains($message, 'ux_payment_event_payload_digest');
    }
}
