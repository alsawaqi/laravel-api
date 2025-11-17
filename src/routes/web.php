<?php

use App\Models\User;
use App\Mail\TestEmail;
use App\Events\OrderPlaced;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use App\Models\ConxDatabaseNotification;
use App\Notifications\NewOrderNotification;
use Pusher\PushNotifications\PushNotifications;


Route::get('/test', function () {
    try {
        event(new OrderPlaced(
            999,
            'ORD-TEST-1',
            12.34
        ));

        Log::info('OrderPlaced broadcasted from /api/testt');

        return ['status' => 'ok'];
    } catch (\Throwable $e) {
        Log::error('Failed to broadcast OrderPlaced', [
            'error' => $e->getMessage(),
        ]);

        return response()->json(['error' => $e->getMessage()], 500);
    }
});
