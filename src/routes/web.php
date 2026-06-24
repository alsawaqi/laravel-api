<?php

use App\Models\User;
use App\Mail\TestEmail;
use App\Events\OrderPlaced;
use App\Mail\TestQueuedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use App\Models\ConxDatabaseNotification;
use App\Notifications\NewOrderNotification;
use Pusher\PushNotifications\PushNotifications;


// Route::get('/test', function () {
//     try {
//         event(new OrderPlaced(
//             999,
//             'ORD-TEST-1',
//             12.34
//         ));

//         Log::info('OrderPlaced broadcasted from /api/testt');

//         return ['status' => 'ok'];
//     } catch (\Throwable $e) {
//         Log::error('Failed to broadcast OrderPlaced', [
//             'error' => $e->getMessage(),
//         ]);

//         return response()->json(['error' => $e->getMessage()], 500);
//     }
// });




Route::get('/dev/test-queued-email', function () {
    $to = request('to', 'PUT_YOUR_EMAIL_HERE');
    Mail::to($to)->queue(new TestQueuedMail('If you received this, your queue + worker are working 🎉'));
    return response()->json(['queued' => true, 'to' => $to]);
});
