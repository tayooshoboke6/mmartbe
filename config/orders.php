<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Order Expiration Settings
    |--------------------------------------------------------------------------
    |
    | This file contains configuration settings related to order management,
    | including expiration timeframes and notification settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Expiration Timeframe
    |--------------------------------------------------------------------------
    |
    | The number of hours after which unpaid orders will be automatically expired.
    | Default is 24 hours (1 day).
    |
    */
    'expiration_hours' => env('ORDER_EXPIRATION_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configure whether to send notifications when orders expire.
    |
    */
    'send_expiration_notifications' => env('SEND_ORDER_EXPIRATION_NOTIFICATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Analytics Settings
    |--------------------------------------------------------------------------
    |
    | Configure whether to record analytics about expired orders.
    |
    */
    'record_expiration_analytics' => env('RECORD_ORDER_EXPIRATION_ANALYTICS', true),
];
