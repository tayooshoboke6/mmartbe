<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Brevo API Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the Brevo API integration.
    |
    */

    'key' => env('BREVO_API_KEY', ''),
    
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@mmart.com'),
        'name' => env('MAIL_FROM_NAME', 'M-Mart+ Support'),
    ],
];
