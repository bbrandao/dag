<?php

return [
    'public_url' => env('DAG_PUBLIC_URL'),
    'phone_key' => env('DAG_PHONE_KEY'),
    'otp_key' => env('DAG_OTP_KEY'),
    'uazapi' => [
        'url' => env('UAZAPI_BASE_URL'),
        'token' => env('UAZAPI_INSTANCE_TOKEN'),
        'sender' => env('WHATSAPP_SENDER_NUMBER'),
    ],
];
