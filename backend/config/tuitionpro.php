<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Logins allowed per minute from one public IP. Keep this high: many students share one IP
    // (school computer labs, mobile carrier NAT). Per-account guessing is limited separately.
    'login_attempts_per_ip' => (int) env('LOGIN_ATTEMPTS_PER_IP', 600),

    'sms' => [
        // log: write messages to the sms_logs table only (development)
        // notifylk: send through Notify.lk (popular Sri Lankan SMS gateway)
        'driver' => env('SMS_DRIVER', 'log'),
        'notifylk' => [
            'endpoint' => 'https://app.notify.lk/api/v1/send',
            'user_id' => env('NOTIFYLK_USER_ID'),
            'api_key' => env('NOTIFYLK_API_KEY'),
            'sender_id' => env('NOTIFYLK_SENDER_ID', 'NotifyDEMO'),
        ],
    ],

    'payhere' => [
        'merchant_id' => env('PAYHERE_MERCHANT_ID'),
        'merchant_secret' => env('PAYHERE_MERCHANT_SECRET'),
        'sandbox' => env('PAYHERE_SANDBOX', true),
    ],

    'grades' => ['Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'O/L', 'A/L', 'Other'],
    'mediums' => ['Sinhala', 'Tamil', 'English'],
];
