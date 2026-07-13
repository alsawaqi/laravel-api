<?php

use App\Services\Orders\CustomerUnpaidAmwalOrderCancellationService;
use App\Services\Payments\Amwal\AmwalPaymentException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('amwal:expire-unpaid-orders {--minutes=}', function () {
    if (! Schema::hasTable('Orders_Placed_T')) {
        $this->warn('Orders table is unavailable.');

        return Command::FAILURE;
    }

    $configuredTtl = (int) config('services.amwal.pending_order_ttl_minutes', 30);
    $minutes = max(1, (int) ($this->option('minutes') ?: $configuredTtl));
    $cutoff = now()->subMinutes($minutes);
    $timeColumn = Schema::hasColumn('Orders_Placed_T', 'Checkout_Submitted_At')
        ? 'Checkout_Submitted_At'
        : 'created_at';
    $cancelled = 0;

    $candidates = DB::table('Orders_Placed_T')
        ->select(['id', 'Customers_Id'])
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
        });

    if ($timeColumn === 'Checkout_Submitted_At') {
        $candidates->where(function ($query) use ($cutoff) {
            $query->where('Checkout_Submitted_At', '<', $cutoff)
                ->orWhere(function ($legacy) use ($cutoff) {
                    $legacy->whereNull('Checkout_Submitted_At')
                        ->where('created_at', '<', $cutoff);
                });
        });
    } else {
        $candidates->where('created_at', '<', $cutoff);
    }

    if (Schema::hasTable('Payment_Gateway_Attempts_T')) {
        $candidates->whereNotExists(function ($query) use ($cutoff) {
            $query->selectRaw('1')
                ->from('Payment_Gateway_Attempts_T as expiry_attempt')
                ->whereColumn('expiry_attempt.Orders_Placed_Id', 'Orders_Placed_T.id')
                ->where('expiry_attempt.Gateway', 'amwal_smartbox')
                ->whereIn('expiry_attempt.Status', ['pending', 'initiated', 'requires_action'])
                ->where(function ($activity) use ($cutoff) {
                    $activity->where('expiry_attempt.Initiated_At', '>=', $cutoff)
                        ->orWhere('expiry_attempt.updated_at', '>=', $cutoff);
                });
        });
    }

    $candidates->orderBy('id')
        ->chunkById(100, function ($orders) use (&$cancelled, $cutoff) {
            $service = app(CustomerUnpaidAmwalOrderCancellationService::class);

            foreach ($orders as $order) {
                try {
                    $result = $service->cancel(
                        orderId: (int) $order->id,
                        customerId: (int) $order->Customers_Id,
                        customerUserId: 0,
                        actorName: 'Automated AmwalPay expiry',
                        source: 'system_expiry',
                        restoreCart: true,
                        expiryCutoff: $cutoff,
                    );

                    if (! $result['idempotent']) {
                        $cancelled++;
                    }
                } catch (AmwalPaymentException $exception) {
                    // The row may have been paid or cancelled after the candidate
                    // scan. The cancellation service's locks and captured-state
                    // checks remain authoritative.
                    Log::notice('Skipped AmwalPay expiry candidate.', [
                        'order_id' => (int) $order->id,
                        'status' => $exception->status,
                        'reason' => $exception->getMessage(),
                    ]);
                }
            }
        });

    $this->info("Cancelled {$cancelled} expired unpaid AmwalPay order(s).");

    return Command::SUCCESS;
})->purpose('Release reservations for expired unpaid AmwalPay card orders.');

Schedule::useCache(config('cache.scheduler_store', 'file'));

Schedule::command('amwal:expire-unpaid-orders')
    ->everyMinute()
    ->withoutOverlapping(10);
