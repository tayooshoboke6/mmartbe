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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', '608587869148-iv4domm1d4h4ah198027feaplts05iae.apps.googleusercontent.com'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', 'http://localhost:3000/auth/google/callback'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', 'com.mmartplus.app'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI', 'http://localhost:3000/auth/apple/callback'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
        'api_key' => env('BREVO_API_KEY'),
        'api_secret' => env('BREVO_API_SECRET'),
    ],

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'api_url' => env('TERMII_API_URL', 'https://api.ng.termii.com/api'),
        'sender_id' => env('TERMII_SENDER_ID', 'MMartPlus'),
    ],

];
