# اعلان‌ها (Notifications)

اینباکس کاربر، خوانده/نخوانده، حذف، پیشنهادهای زمینه‌ای، ثبت توکن پوش، و ارسال ادمین.

## تنظیمات (`config/config.php`)

```php
'notifications' => [
    'enabled' => true,
    'seed_catalog' => true,          // اعلان‌های اولیهٔ کاتالوگ برای هر کاربر
    'expo_push_enabled' => true,     // ارسال از طریق Expo Push API
    'expo_push_url' => 'https://exp.host/--/api/v2/push/send',
    'push_sound' => 'default',
    'suggestion_stagger_seconds' => 300,
],
```

فلگ Remote Config: `features.notifications_enabled`

## اندپوینت‌ها (Bearer مگر ادمین)

| روش | مسیر | توضیح |
|-----|------|--------|
| GET | `/notifications` | لیست اینباکس + `unread_count` |
| GET | `/notifications/unread-count` | فقط تعداد خوانده‌نشده |
| GET | `/notifications/suggestions` | پیشنهادهای contextual (ذخیره نمی‌شوند) |
| POST | `/notifications/read` | `{ "id": "welcome" }` |
| POST | `/notifications/read-all` | همه را خوانده کن |
| POST | `/notifications/dismiss` | حذف از اینباکس |
| POST | `/devices/push-token` | ثبت Expo/FCM token |
| POST | `/devices/push-token/remove` | حذف توکن |
| POST | `/admin/notifications/send` | ارسال ادمین (`X-Admin-Key`) |

### شکل اعلان

```json
{
  "id": "welcome",
  "title": "...",
  "body": "...",
  "createdAt": "2026-06-04T08:00:00+00:00",
  "kind": "welcome",
  "read": false,
  "dismissed": false,
  "source": "catalog",
  "deepLink": "/learn"
}
```

`kind`: `welcome` | `challenge` | `compete` | `lesson` | `practice` | `premium`

### ارسال ادمین

```json
POST /admin/notifications/send
X-Admin-Key: <admin_key>
{
  "title": "عنوان",
  "body": "متن",
  "kind": "lesson",
  "user_id": 12,
  "deep_link": "/learn"
}
```

هدف‌ها:
- `user_id` / `userId` — یک کاربر
- `user_ids` — چند کاربر
- `broadcast: true` یا `all: true` — همه کاربران

پنل ساده: `public/admin/notifications.html`

### رویدادهای خودکار (سیستم)

| رویداد | public_id نمونه |
|--------|------------------|
| خرید سکه | `shop-coins-{orderToken}` |
| VIP | `shop-vip-{orderToken}` |
| پایان مسابقه | `match-{matchToken}` |
| چالش روزانه | `daily-{dayKey}-won/lost` |

اگر توکن پوش ثبت شده باشد، Expo Push هم ارسال می‌شود (`data.notificationId/title/body/kind`).

## کلاینت اپ

- `src/services/notifications/notificationService.ts`
- `NotificationsSyncBridge` — همگام بعد از لاگین
- `NotificationsScreen` — pull-to-refresh
- خواندن اعلان → `POST /notifications/read`

## تست CLI

```bash
php scripts/test_notifications.php
```
