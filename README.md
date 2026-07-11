# Al Najmo Thagheb — PHP Auth API

API ورود با موبایل، کد OTP، ثبت‌نام و توکن نشست.

## پیش‌نیاز
- PHP 8.1+ با `pdo_sqlite` (پیش‌فرض) یا `pdo_mysql`، همچنین `curl`, `json`, `mbstring`
- به‌صورت پیش‌فرض از **SQLite** استفاده می‌شود (فایل `storage/database.sqlite`) — بدون نیاز به MySQL

## نصب سریع

1. تنظیمات در `config/config.php` (پیش‌فرض SQLite آماده است).

2. سرور را اجرا کنید:
```bash
cd D:\api
start-server.bat
```
یا:
```bash
php -S 0.0.0.0:8080 -t public public/router.php
```

3. تست سلامت:
```
GET http://127.0.0.1:8080/health
```

### MySQL (اختیاری)
```bash
mysql -u root < database/schema.sql
```
سپس در `config/config.php`:
```php
'db' => [ 'driver' => 'mysql', 'user' => 'root', 'pass' => '', 'name' => 'alnajmo_thagheb', ... ]
```

برای گوشی واقعی در شبکه محلی، IP کامپیوتر را جای `127.0.0.1` بگذارید، مثلاً:
`http://192.168.1.10:8080`

اگر از XAMPP Apache استفاده می‌کنید، پوشه را به `C:\xampp\htdocs\api` لینک/کپی کنید و به
`http://127.0.0.1/api/public` وصل شوید.

## اندپوینت‌ها

### `POST /auth/otp/send`
```json
{ "phone": "09123456789" }
```
پاسخ (حالت دمو):
```json
{
  "ok": true,
  "message": "کد تأیید ارسال شد.",
  "data": {
    "phone": "09123456789",
    "expires_in": 300,
    "resend_after": 60,
    "demo_code": "123456"
  }
}
```

### `POST /auth/otp/verify`
```json
{ "phone": "09123456789", "code": "123456" }
```
اگر کاربر از قبل باشد:
```json
{
  "ok": true,
  "data": {
    "status": "authenticated",
    "token": "...",
    "profile": { "...": "..." }
  }
}
```
اگر کاربر جدید باشد:
```json
{
  "ok": true,
  "data": {
    "status": "needs_register",
    "registration_token": "...",
    "phone": "09123456789"
  }
}
```

### `POST /auth/register`
با `registration_token` و فیلدهای فرم ثبت‌نام (camelCase یا snake_case).

### `POST /auth/register/skip`
```json
{ "registration_token": "..." }
```

### `GET /auth/me`
هدر: `Authorization: Bearer <token>`

### `POST /auth/logout`
هدر: `Authorization: Bearer <token>`

## پیشرفت و اقتصاد
جزئیات کامل: [`PROGRESS.md`](./PROGRESS.md)

- `GET /progress` — وضعیت سکه/قلب/مسیر (با regen قلب)
- `POST /progress/import` — فقط وقتی سرور خالی است (`revision = 0`)
- `POST /progress/action` — اکشن‌های authoritative مثل `complete_lesson`, `wrong_answer`, `refill_hearts`, …

## چالش روزانه
جزئیات کامل: [`DAILY_CHALLENGE.md`](./DAILY_CHALLENGE.md)

- `GET /daily-challenge`
- `POST /daily-challenge/start|commit|submit`

## مسابقه زنده
جزئیات کامل: [`COMPETE_MATCH.md`](./COMPETE_MATCH.md)

- `POST /matches/start|commit|finish|cancel`
- `GET /matches/active|get|history`
- `GET /compete/leaderboard`

## فروشگاه و پرداخت
جزئیات کامل: [`SHOP.md`](./SHOP.md)

- `GET /shop/catalog`
- `POST /shop/orders` · `GET /shop/orders/get|history`
- `POST /shop/orders/verify` · `POST /shop/payments/mock-complete`
- `GET /shop/payments/callback`

## اعلان‌ها
جزئیات کامل: [`NOTIFICATIONS.md`](./NOTIFICATIONS.md)

- `GET /notifications` · unread-count · suggestions
- `POST /notifications/read|read-all|dismiss`
- `POST /devices/push-token` · remove
- `POST /admin/notifications/send` (+ پنل `admin/notifications.html`)

## پشتیبانی و تیکیت
جزئیات کامل: [`SUPPORT.md`](./SUPPORT.md)

- `GET /support/contact`
- `GET/POST /support/tickets` · message · close · seen
- ادمین: `admin/support.html`

## پروفایل
جزئیات کامل: [`PROFILE.md`](./PROFILE.md)

- `GET/PUT /profile` · `/profile/settings` · `/profile/avatar`

وضعیت کلی قابلیت‌ها: [`SERVER_STATUS.md`](./SERVER_STATUS.md)

## پیامک واقعی (کاوه‌نگار)
در `config/config.php`:
```php
'otp' => [ 'demo_mode' => false, ... ],
'sms' => [
  'provider' => 'kavenegar',
  'kavenegar' => [
    'api_key' => 'YOUR_KEY',
    'template' => 'alnajmo-verify', // قالب Verify Lookup
    'sender' => '',
  ],
],
```

در حالت `demo_mode = true` کد در پاسخ API و در `storage/logs/sms.log` هم نوشته می‌شود.
