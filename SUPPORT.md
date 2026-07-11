# پشتیبانی و تیکیت‌ها (Support)

## اندپوینت‌های کاربر

| روش | مسیر | Auth | توضیح |
|-----|------|------|--------|
| GET | `/support/contact` | خیر | ایمیل/تلفن/تلگرام/واتساپ/ساعات |
| GET | `/support/tickets` | بله | لیست + `unread_count` |
| GET | `/support/tickets/unread-count` | بله | تعداد خوانده‌نشده |
| GET | `/support/tickets/get?id=` | بله | جزئیات |
| POST | `/support/tickets` | بله | ایجاد تیکیت |
| POST | `/support/tickets/message` | بله | پاسخ کاربر |
| POST | `/support/tickets/close` | بله | بستن توسط کاربر |
| POST | `/support/tickets/seen` | بله | علامت خوانده‌شدن |

### ایجاد

```json
{
  "subject": "مشکل پخش صدا",
  "message": "در درس ۳ صدا پخش نمی‌شود.",
  "category": "ticket",
  "priority": "normal"
}
```

`category`: `ticket` | `quick_message` | `callback_request`  
`status`: `open` | `answered` | `closed`

## ادمین (`X-Admin-Key`)

| روش | مسیر |
|-----|------|
| GET | `/admin/support/tickets` |
| GET | `/admin/support/tickets/get?id=` |
| POST | `/admin/support/reply` |
| POST | `/admin/support/status` |

پنل: `public/admin/support.html`

با پاسخ ادمین، اعلان داخل‌برنامه‌ای برای کاربر ارسال می‌شود.

## تنظیمات (`config/config.php`)

```php
'support' => [
  'enabled' => true,
  'email' => 'support@alnajmothagheb.com',
  'phone' => '',
  'telegram' => '',
  'whatsapp' => '',
  'hours' => 'شنبه تا پنج‌شنبه، ۹ تا ۱۷',
  'message' => 'تیم پشتیبانی پاسخگوی شماست.',
  'notify_email' => '', // ایمیل داخلی برای تیکیت جدید
],
```

## کلاینت

- `src/services/support/supportService.ts`
- `SupportScreen` / `SupportTicketDetailScreen`
- همگام بعد از لاگین از طریق `ProfileSyncBridge`

## تست

```bash
php scripts/test_support.php
```
