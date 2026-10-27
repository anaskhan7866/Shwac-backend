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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        // link  = (default, free) messages are prepared as wa.me links; staff tap "Send on WhatsApp"
        //         and press send in their own WhatsApp / WhatsApp Business app.
        // cloud = automatic sending through the paid Meta WhatsApp Cloud API (next stage).
        'mode' => env('WHATSAPP_MODE', 'link'),
        // Meta WhatsApp Cloud API (only used when mode = cloud)
        'enabled' => env('WHATSAPP_ENABLED', false),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),      // verifies X-Hub-Signature-256 on webhooks
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),  // webhook subscription handshake
    ],

    // SMS gateway (used for login OTPs). Works with any Indian DLT provider that has an HTTP API
    // (MSG91, Fast2SMS, Textlocal, 2Factor…). Placeholders: {phone} {phone10} {message} {otp}
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),          // log | http
        'method' => env('SMS_HTTP_METHOD', 'GET'),     // GET | POST
        'url' => env('SMS_HTTP_URL'),                  // e.g. https://provider/api?key=…&to={phone}&msg={message}
        'body' => env('SMS_HTTP_BODY'),                // POST only: JSON with placeholders
        'headers' => env('SMS_HTTP_HEADERS'),          // JSON object, e.g. {"authorization":"KEY"}
        'otp_text' => env('SMS_OTP_TEXT', '{otp} is your SHWAC login code. It is valid for 5 minutes. Do not share it. -SHWAC'),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
    ],

    'upi' => [
        'vpa' => env('UPI_VPA'),           // e.g. shwac@okaxis
        'payee_name' => env('UPI_PAYEE_NAME', 'Shree Hargangeshwar Water Care'),
    ],

];
