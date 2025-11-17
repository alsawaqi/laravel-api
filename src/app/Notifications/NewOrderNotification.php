<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification
{
    //use Queueable;

   public array $orderData;

    public function __construct(array $orderData)
    {
        $this->orderData = $orderData;
    }

    // Store in database
    public function via($notifiable)
    {
        return ['database'];
    }

    // What gets stored in the 'data' column
    public function toArray($notifiable)
    {
        return [
            'order_id' => $this->orderData['id'],
            'customer_name' => $this->orderData['customer_name'],
            'total' => $this->orderData['total'],
            'message' => "New order #{$this->orderData['id']} from {$this->orderData['customer_name']}",
        ];
    }
}