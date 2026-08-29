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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'assets' => [
        'url' => env('ASSET_URL', 'https://assets.cherishlyng.com'),
        'key' => env('ASSET_API_KEY'),
    ],
    'gateway' => [
        'url' => env('GATEWAY_URL'),
        'api_key' => env('GATEWAY_API_KEY'),
        'client_id' => env('GATEWAY_CLIENT_ID', 'CHERISHLY_PROD_001'),
        'callback_url' => env('GATEWAY_CALLBACK_URL', 'https://cherishlyng.com/payment/callback'),
        'webhook_secret' => env('GATEWAY_WEBHOOK_SECRET', ''),
    ],
];
