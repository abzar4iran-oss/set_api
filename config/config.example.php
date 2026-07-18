<?php
/**
 * Copy to config.php and fill in your values.
 */
return [
    'app_name' => 'Al Najmo Thagheb API',
    'env' => 'local',
    'debug' => true,
    // Laragon: http://localhost/api/public  |  PHP built-in: http://127.0.0.1:8080
    'base_url' => 'http://localhost/api/public',

    'db' => [
        'driver' => 'sqlite', // sqlite | mysql — SQLite auto-migrates; prefer it locally
        'sqlite_path' => dirname(__DIR__) . '/storage/database.sqlite',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'alnajmo_thagheb',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'cors' => [
        'allowed_origins' => ['*'],
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => 300,
        'resend_cooldown_seconds' => 60,
        'max_attempts' => 5,
        // local demo: true + demo_code. real SMS: false
        'demo_mode' => false,
        'demo_code' => null,
    ],

    'auth' => [
        'token_ttl_days' => 60,
        'registration_token_ttl_minutes' => 30,
    ],

    'sms' => [
        'provider' => 'kavenegar', // log | kavenegar
        'kavenegar' => [
            'api_key' => '',
            'template' => 'tamplate1', // Verify Lookup template name
            'sender' => '', // required only if template is empty
        ],
    ],

    'admin' => [
        'api_key' => 'change-me-admin-key',
    ],

    'payments' => [
        'provider' => 'mock', // mock | zarinpal
        'allow_direct_grant' => false,
        'order_ttl_minutes' => 30,
        'callback_path' => '/shop/payments/callback',
        // Must match app.json scheme: alnajmothagheb
        'app_return_url' => 'alnajmothagheb://shop/result',
        'zarinpal' => [
            'merchant_id' => '',
            'sandbox' => true,
            'description_prefix' => 'آل‌نجم ثاقب',
        ],
    ],

    'notifications' => [
        'enabled' => true,
        'seed_catalog' => true,
        'expo_push_enabled' => true,
        'expo_push_url' => 'https://exp.host/--/api/v2/push/send',
        'push_sound' => 'default',
        'suggestion_stagger_seconds' => 300,
    ],

    'profile' => [
        'avatar_max_bytes' => 2097152,
    ],

    'support' => [
        'enabled' => true,
        'email' => 'support@alnajmothagheb.com',
        'phone' => '',
        'telegram' => '',
        'whatsapp' => '',
        'hours' => 'شنبه تا پنج‌شنبه، ۹ تا ۱۷',
        'message' => 'تیم پشتیبانی پاسخگوی شماست.',
        'notify_email' => '',
    ],
];
