<?php

namespace App\Services\Checkout;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ActiveAmwalCheckoutGuard
{
    public function blockingOrder(int $customerId): ?object
    {
        return DB::table('Orders_Placed_T')
            ->where('Customers_Id', $customerId)
            ->where('Payment_Method', 'card')
            ->whereIn('Status', ['pending', 'on-hold'])
            ->where(function ($query) {
                $query->whereNull('Payment_Status')
                    ->orWhereNotIn('Payment_Status', [
                        'paid',
                        'paid_requires_review',
                        'cancelled',
                        'refunded',
                        'partially_refunded',
                    ]);
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first(['id', 'Order_Code', 'Payment_Status']);
    }

    public function reconciliationOrder(int $customerId, ?int $excludeOrderId = null): ?object
    {
        $hasGatewayAttempts = Schema::hasTable('Payment_Gateway_Attempts_T');

        return DB::table('Orders_Placed_T')
            ->where('Customers_Id', $customerId)
            ->where('Payment_Method', 'card')
            ->when($excludeOrderId !== null, fn ($query) => $query->where('id', '<>', $excludeOrderId))
            ->where(function ($review) use ($hasGatewayAttempts) {
                $review->where('Payment_Status', 'paid_requires_review');

                if ($hasGatewayAttempts) {
                    $review->orWhereExists(function ($attempt) {
                        $attempt->selectRaw('1')
                            ->from('Payment_Gateway_Attempts_T as review_attempt')
                            ->whereColumn('review_attempt.Orders_Placed_Id', 'Orders_Placed_T.id')
                            ->where('review_attempt.Gateway', 'amwal_smartbox')
                            ->where('review_attempt.Status', 'paid_requires_review');
                    });
                }
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first(['id', 'Order_Code', 'Payment_Status', 'Total_Price']);
    }

    public function recentCancelledOrder(int $customerId, ?int $excludeOrderId = null): ?object
    {
        $cooldownSeconds = $this->retryCooldownSeconds();

        if ($cooldownSeconds <= 0 || ! Schema::hasTable('Payment_Gateway_Attempts_T')) {
            return null;
        }

        return DB::table('Orders_Placed_T')
            ->where('Customers_Id', $customerId)
            ->where('Payment_Method', 'card')
            ->where('Status', 'cancelled')
            ->where('Payment_Status', 'cancelled')
            ->when($excludeOrderId !== null, fn ($query) => $query->where('id', '<>', $excludeOrderId))
            ->where('updated_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereExists(function ($attempt) {
                $attempt->selectRaw('1')
                    ->from('Payment_Gateway_Attempts_T as cancelled_attempt')
                    ->whereColumn('cancelled_attempt.Orders_Placed_Id', 'Orders_Placed_T.id')
                    ->where('cancelled_attempt.Gateway', 'amwal_smartbox');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first(['id', 'Order_Code', 'updated_at']);
    }

    public function retryAfterSeconds(object $cancelledOrder): int
    {
        $cancelledAt = Carbon::parse((string) $cancelledOrder->updated_at);
        $retryAt = $cancelledAt->copy()->addSeconds($this->retryCooldownSeconds());

        return max(1, $retryAt->getTimestamp() - now()->getTimestamp());
    }

    public function retryCooldownSeconds(): int
    {
        return max(0, (int) config('services.amwal.retry_cooldown_seconds', 120));
    }
}
