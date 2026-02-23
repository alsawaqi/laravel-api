<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $notification;

    public function __construct($order, $notification)
    {
        $this->order = $order;
        $this->notification = $notification;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('notifications');
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => $this->order,
            'notification' => $this->notification,
        ];
    }
}