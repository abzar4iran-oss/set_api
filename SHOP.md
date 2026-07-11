# فروشگاه و پرداخت (Shop / Payments)

سکه و VIP فقط بعد از ایجاد سفارش و تأیید پرداخت اعطا می‌شوند.
اعطای رایگان از `POST /progress/action` (`purchase_coin_pack` / `activate_premium`) فقط وقتی
`payments.allow_direct_grant = true` باشد مجاز است (پیش‌فرض: **false**).

## تنظیمات (`config/config.php`)

```php
'payments' => [
    'provider' => 'mock',          // mock | zarinpal
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
```

کاتالوگ قیمت/سکه/مدت VIP از Remote Config (`shop.vip_plan`, `shop.coin_packs`) می‌آید:
- `amount_irr` — مبلغ به **ریال**
- `coins` / `duration_days` / `active`

## اندپوینت‌ها

| روش | مسیر | توضیح |
|-----|------|--------|
| GET | `/shop/catalog` | کاتالوگ + provider (بدون الزام auth) |
| POST | `/shop/orders` | ایجاد سفارش — Bearer |
| GET | `/shop/orders/get?token=` | جزئیات سفارش |
| GET | `/shop/orders/history` | تاریخچه |
| POST | `/shop/orders/verify` | تأیید پرداخت بعد از درگاه |
| POST | `/shop/payments/mock-complete` | تکمیل آزمایشی (فقط mock) |
| GET | `/shop/payments/callback` | کال‌بک درگاه |
| GET | `/shop/payments/mock-pay` | صفحه پرداخت آزمایشی |

### ایجاد سفارش

```json
POST /shop/orders
Authorization: Bearer <token>
{
  "product_type": "coin_pack",
  "product_id": "c1",
  "client_request_id": "optional-idempotency-key"
}
```

پاسخ شامل `order`, `payment_url`, `authority`, `progress`.

### جریان mock (توسعه)

1. `POST /shop/orders`
2. `POST /shop/payments/mock-complete` با `{ "order_token": "..." }`
3. سکه / VIP روی پیشرفت کاربر اعمال می‌شود (`shop_fulfill`)

### جریان Zarinpal (تولید)

1. `provider = zarinpal` + `merchant_id`
2. کاربر را به `payment_url` بفرستید
3. کال‌بک سرور یا `POST /shop/orders/verify` سفارش را fulfill می‌کند

## کلاینت اپ

- سرویس: `src/services/shop/shopService.ts`
- صفحه: `SubscribeScreen` — کاربر واقعی → سفارش + mock/verify؛ مهمان → فقط محلی

## تست CLI

```bash
php scripts/test_shop.php
```
