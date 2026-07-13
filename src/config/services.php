<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],


    'pusher_beams' => [
    'instance_id' => env('PUSHER_BEAMS_INSTANCE_ID'),
    'secret_key'  => env('PUSHER_BEAMS_SECRET_KEY'),
],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'amwal' => [
        'enabled' => (bool) env('AMWAL_ENABLED', false),
        'environment' => env('AMWAL_ENVIRONMENT', 'uat'),
        'merchant_id' => env('AMWAL_MERCHANT_ID'),
        'terminal_id' => env('AMWAL_TERMINAL_ID'),
        'secure_key' => env('AMWAL_SECURE_KEY'),
        'currency_id' => (string) env('AMWAL_CURRENCY_ID', '512'),
        'smartbox_url' => env('AMWAL_SMARTBOX_URL'),
        'payment_view_type' => (int) env('AMWAL_PAYMENT_VIEW_TYPE', 1),
        'contact_info_type' => (int) env('AMWAL_CONTACT_INFO_TYPE', 1),
        'pending_order_ttl_minutes' => (int) env('AMWAL_PENDING_ORDER_TTL_MINUTES', 30),
        'retry_cooldown_seconds' => (int) env('AMWAL_RETRY_COOLDOWN_SECONDS', 120),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
