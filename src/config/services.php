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

    /*
    |--------------------------------------------------------------------------
    | Thawani Checkout
    |--------------------------------------------------------------------------
    |
    | This is intentionally disabled until Thawani supplies the merchant's
    | UAT credentials and confirms the final session/webhook contract. Keep
    | all credentials in the Laravel environment; never expose them through
    | Nuxt public runtime configuration.
    |
    */
    'thawani' => [
        'enabled' => (bool) env('THAWANI_ENABLED', false),
        'environment' => env('THAWANI_ENVIRONMENT', 'uat'),
        'api_base_url' => env('THAWANI_API_BASE_URL'),
        'checkout_base_url' => env('THAWANI_CHECKOUT_BASE_URL'),
        'secret_key' => env('THAWANI_SECRET_KEY'),
        'publishable_key' => env('THAWANI_PUBLISHABLE_KEY'),
        'webhook_secret' => env('THAWANI_WEBHOOK_SECRET'),
        'request_timeout_seconds' => (int) env('THAWANI_REQUEST_TIMEOUT_SECONDS', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
