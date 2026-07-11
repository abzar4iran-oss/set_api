<?php
/**
 * Copy to config.php and fill in your values.
 */
return [
    'app_name' => 'Al Najmo Thagheb API',
    'env' => 'local',
    'debug' => true,
    'base_url' => 'http://127.0.0.1:8080',

    'db' => [
        'driver' => 'sqlite', // sqlite | mysql
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
        'demo_mode' => true,
        'demo_code' => '123456',
    ],

    'auth' => [
        'token_ttl_days' => 60,
        'registration_token_ttl_minutes' => 30,
    ],

    'sms' => [
        'provider' => 'log',
        'kavenegar' => [
            'api_key' => '',
            'template' => '',
            'sender' => '',
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
        'app_return_url' => 'alnajmo://shop/result',
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
