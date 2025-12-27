<?php

namespace App\Services;

use Pusher\PushNotifications\PushNotifications;

class BeamsClient
{
    protected PushNotifications $client;

    public function __construct()
    {
        $this->client = new PushNotifications([
            'instanceId' => 'e1944000-0a47-4005-9ac3-1f0480b9ae16',
            'secretKey'  => '34F3C8B8F33EBBD64C40708F28AA2882B8CB225C820F430014AE8D9F5447E430',
        ]);
    }

    // ⬇️ change `: array` to `: void`
    public function notifyAdmins(string $title, string $body, array $data = []): void
    {
        $this->client->publishToInterests(
            ['admins'],
            [
                'web' => [
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                        'icon'  => 'https://avinaq.com/logonew1.jpg',
                        'deep_link' => 'https://admin.avinaq.com/admin/orders/ordersplaced',
                    ],
                    'data' => $data,
                ],
            ]
        );
    }
}
