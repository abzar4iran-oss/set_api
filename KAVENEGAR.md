# کاوه‌نگار — OTP واقعی

وضعیت فعلی در `config/config.php`:

- `otp.demo_mode` = `false` (کد تصادفی، نه `123456`)
- `sms.provider` = `kavenegar`
- `sms.kavenegar.template` = `tamplate1`
- `sms.kavenegar.sender` = `0018018949161` (فقط وقتی template خالی باشد)

## قالب Verify Lookup

1. پنل کاوه‌نگار → **اعتبارسنجی / Verify**
2. نام قالب باید دقیقاً با config یکی باشد: `tamplate1`
3. متن قالب باید شامل `%token` باشد (مثلاً):

```text
کد تأیید النجم ثاقب: %token
```

4. قالب باید توسط کاوه‌نگار **تأیید** شده باشد.

اگر نام قالب فرق دارد، فقط همین خط را عوض کن:

```php
'template' => 'NAME_EXACTLY_AS_IN_PANEL',
```

## تست سریع

```bash
curl -X POST http://localhost/api/public/auth/otp/send ^
  -H "Content-Type: application/json" ^
  -d "{\"phone\":\"09XXXXXXXXX\"}"
```

پاسخ موفق نباید `demo_code` داشته باشد. کد فقط روی گوشی پیامک می‌شود.

لاگ ارسال: `storage/logs/sms.log`

## امنیت

کلید API محرمانه است. بعد از راه‌اندازی، از پنل کلید را عوض/بازتولید کن و فقط در `config.php` بگذار.
