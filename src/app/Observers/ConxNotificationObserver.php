<?php

namespace App\Observers;

use App\Services\BeamsClient;
use App\Models\ConxDatabaseNotification;

class ConxNotificationObserver
{
    //

      public function __construct(protected BeamsClient $beams) {}

    public function created(ConxDatabaseNotification $notification): void
    {
               $data = $notification->data ?? [];

        // Fallbacks if title/message are missing
        $title   = $data['title']   ?? 'New notification';
        $message = $data['message'] ?? 'You have a new notification.';

        $extra = array_merge($data, [
            'notification_id' => $notification->id,
            'type'            => $notification->type,
            'notifiable_id'   => $notification->notifiable_id,
        ]);

        $this->beams->notifyAdmins($title, $message, $extra);
    }
}
