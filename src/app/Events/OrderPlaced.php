<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPlaced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $orderId;
    public string $orderCode;
    public float $total;

    public function __construct(int $orderId, string $orderCode, float $total)
    {
        $this->orderId   = $orderId;
        $this->orderCode = $orderCode;
        $this->total     = $total;
    }

    /**
     * Public channel "orders"
     */
    public function broadcastOn(): array
    {
        return [new Channel('orders')];
    }

    /**
     * Event name used by Pusher & frontend
     */
    public function broadcastAs(): string
    {
        return 'order.placed';
    }

    /**
     * Data received in Nuxt
     */
    public function broadcastWith(): array
    {
        return [
            'order_id'   => $this->orderId,
            'order_code' => $this->orderCode,
            'total'      => $this->total,
        ];
    }
}
