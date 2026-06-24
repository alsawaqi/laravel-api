<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminNotificationCreated
{
    use SerializesModels;

    public function __construct(
        public array $data
    ) {}

    // public channel
    public function broadcastOn()
    {
        return new Channel('admin.notifications');
    }

    public function broadcastAs()
    {
        return 'new-notification';
    }
}
