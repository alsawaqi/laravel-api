<?php

namespace App\Services\Notifications;

use App\Models\ConxDatabaseNotification;

class CustomerNotificationService
{
    /**
     * @param array<string, mixed> $data
     */
    public function notifyUser(int $userId, string $type, array $data): ConxDatabaseNotification
    {
        return ConxDatabaseNotification::create([
            'type' => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => $userId,
            'data' => $data,
        ]);
    }
}
