<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationBroadcastService
{
    public function sendToAdmin($notification)
    {
        try {
            $response = Http::post('http://nginx:80/api/broadcast-notification', [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => is_string($notification->data) 
                    ? json_decode($notification->data, true) 
                    : $notification->data,
                'created_at' => $notification->created_at->toISOString(),
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Failed to broadcast notification: ' . $e->getMessage());
            return false;
        }
    }
}