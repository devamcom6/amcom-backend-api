<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'upload_url' => env('CLOUDINARY_UPLOAD_URL', 'https://api.cloudinary.com/v1_1'),
        'delivery_url' => env('CLOUDINARY_DELIVERY_URL', 'https://res.cloudinary.com'),
        'timeout' => (int) env('CLOUDINARY_TIMEOUT', 60),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 30),
        // Source IPs Paystack documents for webhooks; empty = don't filter (signature is always verified).
        'webhook_ips' => array_filter(explode(',', (string) env('PAYSTACK_WEBHOOK_IPS', ''))),
    ],

    'zeptomail' => [
        'api_key' => env('ZEPTOMAIL_API_KEY'),
        'endpoint' => env('ZEPTOMAIL_ENDPOINT', 'https://api.zeptomail.com/v1.1/email'),
        'from_address' => env('ZEPTOMAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'from_name' => env('ZEPTOMAIL_FROM_NAME', env('MAIL_FROM_NAME')),
        'bounce_address' => env('ZEPTOMAIL_BOUNCE_ADDRESS'),
        'timeout' => (int) env('ZEPTOMAIL_TIMEOUT', 30),
    ],

    'identity' => [
        // fake (local/dev: accepts well-formed IDs) | persona
        'driver' => env('IDENTITY_DRIVER', 'fake'),
    ],

    'persona' => [
        'api_key' => env('PERSONA_API_KEY'),
        'base_url' => env('PERSONA_BASE_URL', 'https://withpersona.com/api/v1'),
        'inquiry_template_id' => env('PERSONA_INQUIRY_TEMPLATE_ID'),
        'timeout' => (int) env('PERSONA_TIMEOUT', 30),
    ],

    'sms' => [
        // log (writes to the application log) | termii
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'Amcom'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com/api'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Must be the FRONTEND callback route; the frontend posts the code back to the API.
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/auth/google/callback'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
