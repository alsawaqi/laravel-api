<?php

namespace App\Services\Orders;

use App\Services\Payments\Amwal\AmwalPaymentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CustomerUnpaidAmwalOrderCancellationService
{
    /**
     * Cancel a customer's complete unpaid AmwalPay order and release its local
     * reservations. A signed success received after this transaction is handled
     * by AmwalPaymentProcessor as paid_requires_review.
     *
     * @return array<string, mixed>
     */
    public function cancel(
        int $orderId,
        int $customerId,
        int $customerUserId,
        ?string $actorName = null,
        string $source = 'customer',
        bool $restoreCart = false,
        ?\DateTimeInterface $expiryCutoff = null,
    ): array {
        if (! in_array($source, ['customer', 'system_expiry'], true)) {
            throw new \InvalidArgumentException('Unsupported unpaid-order cancellation source.');
        }

        // A customer cancelling SmartBox is cancelling only the payment
        // attempt. Restoring their cart is a server-side invariant and must
        // not depend on a browser-provided flag.
        if ($source === 'customer') {
            $restoreCart = true;
        }

        $actorType = $source === 'system_expiry' ? 'system' : 'customer';
        $actorRole = $source === 'system_expiry' ? 'system' : 'customer';
        $eventSource = $source === 'system_expiry' ? 'system' : 'customer';

        return DB::transaction(function () use (
            $orderId,
            $customerId,
            $customerUserId,
            $actorName,
            $source,
            $actorType,
            $actorRole,
            $eventSource,
            $restoreCart,
            $expiryCutoff,
        ) {
            $lockedCustomer = DB::table('Customers_Master_T')
                ->where('id', $customerId)
                ->lockForUpdate()
                ->first(['id']);

            if (! $lockedCustomer) {
                throw new AmwalPaymentException('The card payment order is not available.', 404);
            }

            $order = DB::table('Orders_Placed_T')
                ->where('id', $orderId)
                ->where('Customers_Id', $customerId)
                ->lockForUpdate()
                ->first();

            if (! $order || strtolower((string) ($order->Payment_Method ?? '')) !== 'card') {
                // Deliberately do not disclose whether another customer's order exists.
                throw new AmwalPaymentException('The card payment order is not available.', 404);
            }

            $saleHeaders = DB::table('Sales_Transaction_Header_T')
                ->where('Orders_Placed_Id', $orderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $payments = $saleHeaders->isNotEmpty()
                ? DB::table('Sales_Transactions_Details_T')
                    ->whereIn('Sales_Transaction_Header_Id', $saleHeaders->pluck('id')->all())
                    ->where('Payment_Gateway', 'amwal_smartbox')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();
            $attempts = Schema::hasTable('Payment_Gateway_Attempts_T')
                ? DB::table('Payment_Gateway_Attempts_T')
                    ->where('Orders_Placed_Id', $orderId)
                    ->where('Gateway', 'amwal_smartbox')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();

            if ($payments->isEmpty() && $attempts->isEmpty()) {
                throw new AmwalPaymentException('The AmwalPay payment record could not be verified.', 409);
            }

            $capturedStates = ['paid', 'paid_requires_review'];
            $captured = in_array(strtolower((string) ($order->Payment_Status ?? '')), $capturedStates, true)
                || $attempts->contains(
                    fn ($attempt) => in_array(strtolower((string) ($attempt->Status ?? '')), $capturedStates, true)
                )
                || $payments->contains(
                    fn ($payment) => in_array(strtolower((string) ($payment->Payment_Status ?? '')), $capturedStates, true)
                );

            if ($captured) {
                throw new AmwalPaymentException(
                    'This payment has already been captured and the order cannot be abandoned.',
                    409,
                );
            }

            if ($source === 'system_expiry' && $expiryCutoff
                && $attempts->contains(
                    fn ($attempt) => $this->attemptWasActiveSince($attempt, $expiryCutoff)
                )) {
                throw new AmwalPaymentException(
                    'The active AmwalPay attempt was refreshed inside the expiry window.',
                    409,
                );
            }

            $activeDetails = DB::table('Orders_Placed_Details_T')
                ->where('Orders_Placed_Id', $orderId)
                ->where('Status', '<>', 'cancelled')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (strtolower((string) ($order->Status ?? '')) === 'cancelled') {
                if ($activeDetails->isNotEmpty()) {
                    throw new AmwalPaymentException(
                        'The cancelled order has active product lines and requires reconciliation.',
                        409,
                    );
                }

                $cartRestoration = $this->storedCartRestoration($payments, $attempts);

                if ($cartRestoration === null) {
                    $cartRestoration = $this->emptyCartRestoration($restoreCart, $source);
                    $cartRestoration['performed'] = false;
                    $cartRestoration['ignored_reason'] = 'order_already_cancelled';
                }

                return [
                    'idempotent' => true,
                    'released_lines' => 0,
                    'released_loyalty_points' => 0,
                    'cart_restoration' => $cartRestoration,
                ];
            }

            if (! in_array(strtolower((string) ($order->Status ?? '')), ['pending', 'on-hold'], true)) {
                throw new AmwalPaymentException('Only a pending unpaid AmwalPay order can be abandoned.', 409);
            }

            if ($activeDetails->isEmpty()) {
                throw new AmwalPaymentException('The unpaid order has no active product lines to release.', 409);
            }

            $productIds = $activeDetails
                ->pluck('Products_Id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->sort()
                ->values();
            $products = DB::table('Products_Master_T')
                ->whereIn('id', $productIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw new AmwalPaymentException('One or more reserved products could not be restored.', 409);
            }

            $loyaltyPoints = max(0, (int) ($order->Loyalty_Points_Redeemed ?? 0));
            $loyalty = null;
            $reversalCode = 'LOYREV-'.$orderId;

            if ($loyaltyPoints > 0) {
                $existingReversal = DB::table('Customers_Loyalty_Transactions_T')
                    ->where('Loyalty_Transaction_Code', $reversalCode)
                    ->lockForUpdate()
                    ->first();

                if ($existingReversal) {
                    throw new AmwalPaymentException(
                        'The loyalty reservation was already released and requires reconciliation.',
                        409,
                    );
                }

                $loyalty = DB::table('Customers_Loyalty_T')
                    ->where('Customer_Id', $customerId)
                    ->lockForUpdate()
                    ->first();

                if (! $loyalty || (int) ($loyalty->Points_Redeemed ?? 0) < $loyaltyPoints) {
                    throw new AmwalPaymentException('The reserved loyalty balance could not be verified.', 409);
                }
            }

            $now = now();
            $quantitiesByProduct = $activeDetails
                ->groupBy(fn ($detail) => (int) $detail->Products_Id)
                ->map(fn ($details) => (int) $details->sum(fn ($detail) => (int) $detail->Quantity));
            $detailIdsByProduct = $activeDetails
                ->groupBy(fn ($detail) => (int) $detail->Products_Id)
                ->map(fn ($details) => $details->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
            $hasProductIsActive = Schema::hasColumn('Products_Master_T', 'Is_Active');
            $hasCartTable = Schema::hasTable('Customers_Carts_T');
            $hasCartSoftDeletes = $hasCartTable
                && Schema::hasColumn('Customers_Carts_T', 'deleted_at');
            $cartRestoration = $this->emptyCartRestoration($restoreCart, $source);

            foreach ($quantitiesByProduct as $productId => $quantity) {
                $product = $products->get((int) $productId);
                $previousStock = (int) ($product->Product_Stock ?? 0);
                $newStock = $previousStock + $quantity;
                $currentStatus = (string) ($product->Status ?? 'available');
                $isDeleted = ! empty($product->deleted_at);
                $isActive = ! $hasProductIsActive || (int) ($product->Is_Active ?? 0) === 1;
                $nextStatus = ! $isDeleted && $isActive
                    && strtolower($currentStatus) === 'out_of_stock' && $newStock > 0
                    ? 'available'
                    : $currentStatus;

                DB::table('Products_Master_T')->where('id', $productId)->update([
                    'Product_Stock' => $newStock,
                    'Status' => $nextStatus,
                    'updated_at' => $now,
                ]);

                if (Schema::hasTable('Product_Stock_Movements_T')) {
                    $vendorId = $activeDetails
                        ->first(fn ($detail) => (int) $detail->Products_Id === (int) $productId)
                        ?->Vendor_Id;

                    DB::table('Product_Stock_Movements_T')->insert([
                        'Products_Id' => $productId,
                        'Vendor_Id' => $vendorId,
                        'Movement_Type' => 'order_cancellation_release',
                        'Quantity_Delta' => $quantity,
                        'Quantity' => $quantity,
                        'Previous_Stock' => $previousStock,
                        'New_Stock' => $newStock,
                        'Actor_Type' => $actorType,
                        'Actor_Id' => $customerUserId > 0 ? $customerUserId : null,
                        'Actor_Name' => $actorName,
                        'Notes' => $source === 'system_expiry'
                            ? "Released expired unpaid AmwalPay order {$orderId} reservation."
                            : "Released unpaid AmwalPay order {$orderId} reservation at customer request.",
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if ($cartRestoration['performed']) {
                    $orderDetailIds = $detailIdsByProduct->get((int) $productId, []);
                    $skipReason = match (true) {
                        ! $hasCartTable => 'cart_table_unavailable',
                        $isDeleted => 'product_deleted',
                        ! $isActive => 'product_inactive',
                        default => null,
                    };

                    if ($skipReason !== null) {
                        $cartRestoration['skipped'][] = [
                            'product_id' => (int) $productId,
                            'quantity' => $quantity,
                            'order_detail_ids' => $orderDetailIds,
                            'reason' => $skipReason,
                        ];
                        $cartRestoration['skipped_lines']++;
                        $cartRestoration['skipped_quantity'] += $quantity;
                        $cartRestoration['review_required'] = true;

                        continue;
                    }

                    $cartQuery = DB::table('Customers_Carts_T')
                        ->where('Customers_Id', $customerId)
                        ->where('Products_Id', $productId);

                    if ($hasCartSoftDeletes) {
                        // Only a current row is eligible. A historical soft-deleted
                        // row remains immutable and a fresh active row is inserted.
                        $cartQuery->whereNull('deleted_at');
                    }

                    $cartRow = $cartQuery->lockForUpdate()->first();
                    $cartAction = 'created';

                    if ($cartRow) {
                        DB::table('Customers_Carts_T')->where('id', $cartRow->id)->update([
                            'Quantity' => (int) $cartRow->Quantity + $quantity,
                            'updated_at' => $now,
                        ]);
                        $cartId = (int) $cartRow->id;
                        $cartAction = 'increased';
                    } else {
                        $cartInsert = [
                            'Customers_Id' => $customerId,
                            'Products_Id' => (int) $productId,
                            'Quantity' => $quantity,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if ($hasCartSoftDeletes) {
                            $cartInsert['deleted_at'] = null;
                        }

                        $cartId = (int) DB::table('Customers_Carts_T')->insertGetId($cartInsert);
                    }

                    $cartRestoration['restored'][] = [
                        'product_id' => (int) $productId,
                        'quantity' => $quantity,
                        'cart_id' => $cartId,
                        'action' => $cartAction,
                        'order_detail_ids' => $orderDetailIds,
                    ];
                    $cartRestoration['restored_lines']++;
                    $cartRestoration['restored_quantity'] += $quantity;
                }
            }

            foreach ($activeDetails as $detail) {
                DB::table('Orders_Placed_Details_T')->where('id', $detail->id)->update([
                    'Status' => 'cancelled',
                    'updated_at' => $now,
                ]);

                // Actor_User_Id points to the admin-user table. Keep it NULL for
                // customer actions and preserve the real customer identity in the
                // external actor fields instead of forging an admin FK.
                if (Schema::hasTable('Order_Process_Log_T')) {
                    $audit = [
                        'Orders_Placed_Id' => $orderId,
                        'Step_Code' => 'CANCELLED',
                        'Status' => 'Cancelled',
                        'Is_External' => 1,
                        'Actor_User_Id' => null,
                        'Actor_Name' => $actorName ?: ($source === 'system_expiry'
                            ? 'Automated AmwalPay expiry'
                            : "Customer #{$customerId}"),
                        'Actor_Role' => $actorRole,
                        'Signed_At' => $now,
                        'Signature_Url' => null,
                        'Signature_Mime' => null,
                        'Notes' => $source === 'system_expiry'
                            ? "Expired unpaid AmwalPay order {$orderId} after the configured payment window."
                            : "Customer user {$customerUserId} abandoned unpaid AmwalPay order {$orderId}.",
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (Schema::hasColumn('Order_Process_Log_T', 'Orders_Placed_Details_Id')) {
                        $audit['Orders_Placed_Details_Id'] = $detail->id;
                    }
                    if (Schema::hasColumn('Order_Process_Log_T', 'Orders_Placed_Details_Cancelled_Id')) {
                        $audit['Orders_Placed_Details_Cancelled_Id'] = null;
                    }

                    DB::table('Order_Process_Log_T')->insert($audit);
                }
            }

            if (Schema::hasTable('Orders_Placed_Vendors_T')) {
                DB::table('Orders_Placed_Vendors_T')->where('Orders_Placed_Id', $orderId)->update([
                    'Status' => 'cancelled',
                    'updated_at' => $now,
                ]);
            }

            DB::table('Orders_Placed_T')->where('id', $orderId)->update([
                'Status' => 'cancelled',
                'Payment_Status' => 'cancelled',
                'updated_at' => $now,
            ]);

            foreach ($payments as $payment) {
                $metadata = json_decode((string) ($payment->Payment_Metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $metadata['cancelled_before_settlement'] = true;
                $metadata['cancelled_at'] = $now->toIso8601String();
                $metadata['cancellation_source'] = $source;
                $metadata['cart_restoration'] = $cartRestoration;
                if ($source === 'customer') {
                    $metadata['cancelled_by_customer_user_id'] = $customerUserId;
                }

                DB::table('Sales_Transactions_Details_T')->where('id', $payment->id)->update([
                    'Payment_Status' => 'cancelled',
                    'Card_Transaction_Id' => null,
                    'Card_Error_Code' => null,
                    'Card_Error_Message' => $source === 'system_expiry'
                        ? 'Payment window expired before settlement.'
                        : 'Order abandoned by the customer before payment settlement.',
                    'Payment_Metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
            }

            foreach ($attempts as $attempt) {
                $attemptMetadata = json_decode((string) ($attempt->Metadata ?? ''), true);
                $attemptMetadata = is_array($attemptMetadata) ? $attemptMetadata : [];
                $attemptMetadata['cart_restoration'] = $cartRestoration;
                $attemptUpdate = [
                    'Metadata' => json_encode($attemptMetadata, JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ];
                $isPendingAttempt = strtolower((string) ($attempt->Status ?? '')) === 'pending';

                if ($isPendingAttempt) {
                    $attemptUpdate['Status'] = 'cancelled';
                    $attemptUpdate['Completed_At'] = $now;
                }

                DB::table('Payment_Gateway_Attempts_T')->where('id', $attempt->id)->update($attemptUpdate);

                if ($isPendingAttempt && Schema::hasTable('Payment_Gateway_Events_T')) {
                    $digest = hash('sha256', "{$source}-cancel|{$orderId}|{$attempt->id}");

                    if (! DB::table('Payment_Gateway_Events_T')->where('Payload_Digest', $digest)->exists()) {
                        DB::table('Payment_Gateway_Events_T')->insert([
                            'Payment_Gateway_Attempt_Id' => $attempt->id,
                            'Orders_Placed_Id' => $orderId,
                            'Gateway' => 'amwal_smartbox',
                            'Source' => $eventSource,
                            'Payload_Digest' => $digest,
                            'Merchant_Reference' => $attempt->Merchant_Reference,
                            'Gateway_Transaction_Id' => null,
                            'Response_Code' => null,
                            'Outcome' => 'cancelled_before_payment',
                            'Processed_At' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            if ($loyaltyPoints > 0 && $loyalty) {
                DB::table('Customers_Loyalty_T')->where('id', $loyalty->id)->update([
                    'Points_Redeemed' => (int) $loyalty->Points_Redeemed - $loyaltyPoints,
                    'updated_at' => $now,
                ]);

                $loyaltyReversal = [
                    'Loyalty_Transaction_Code' => $reversalCode,
                    'Customer_Id' => $customerId,
                    'Orders_Placed_Id' => $orderId,
                    'Points_Earned' => 0,
                    'Points_Redeemed' => -$loyaltyPoints,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('Customers_Loyalty_Transactions_T', 'Redeemed_Amount')) {
                    $loyaltyReversal['Redeemed_Amount'] = -abs((float) ($order->Loyalty_Discount_Amount ?? 0));
                }

                DB::table('Customers_Loyalty_Transactions_T')->insert($loyaltyReversal);
            }

            return [
                'idempotent' => false,
                'released_lines' => $activeDetails->count(),
                'released_loyalty_points' => $loyaltyPoints,
                'cart_restoration' => $cartRestoration,
            ];
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCartRestoration(bool $requested, string $source): array
    {
        $performed = $requested && in_array($source, ['customer', 'system_expiry'], true);

        return [
            'requested' => $requested,
            'performed' => $performed,
            'source' => $source,
            'restored_lines' => 0,
            'restored_quantity' => 0,
            'restored' => [],
            'skipped_lines' => 0,
            'skipped_quantity' => 0,
            'skipped' => [],
            'review_required' => false,
            'ignored_reason' => $performed
                ? null
                : ($requested ? 'source_not_customer' : 'not_requested'),
        ];
    }

    /**
     * @param  iterable<object>  $payments
     * @param  iterable<object>  $attempts
     * @return array<string, mixed>|null
     */
    private function storedCartRestoration(iterable $payments, iterable $attempts): ?array
    {
        foreach ($payments as $payment) {
            $metadata = json_decode((string) ($payment->Payment_Metadata ?? ''), true);

            if (is_array($metadata) && is_array($metadata['cart_restoration'] ?? null)) {
                return $metadata['cart_restoration'];
            }
        }

        foreach ($attempts as $attempt) {
            $metadata = json_decode((string) ($attempt->Metadata ?? ''), true);

            if (is_array($metadata) && is_array($metadata['cart_restoration'] ?? null)) {
                return $metadata['cart_restoration'];
            }
        }

        return null;
    }

    private function attemptWasActiveSince(object $attempt, \DateTimeInterface $cutoff): bool
    {
        if (! in_array(
            strtolower((string) ($attempt->Status ?? '')),
            ['pending', 'initiated', 'requires_action'],
            true,
        )) {
            return false;
        }

        $metadata = json_decode((string) ($attempt->Metadata ?? ''), true);
        $activityValues = [
            $attempt->Initiated_At ?? null,
            $attempt->updated_at ?? null,
            is_array($metadata) ? ($metadata['last_configuration_requested_at'] ?? null) : null,
            is_array($metadata) ? ($metadata['last_configured_at'] ?? null) : null,
        ];

        foreach ($activityValues as $activityValue) {
            if ($activityValue === null || $activityValue === '') {
                continue;
            }

            try {
                if ((new \DateTimeImmutable((string) $activityValue))->getTimestamp() >= $cutoff->getTimestamp()) {
                    return true;
                }
            } catch (\Throwable) {
                // Ignore malformed legacy metadata and continue with the
                // remaining authoritative timestamp columns.
            }
        }

        return false;
    }
}
