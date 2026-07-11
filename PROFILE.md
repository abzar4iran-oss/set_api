# پروفایل، تنظیمات، آواتار و پشتیبانی

## پروفایل

| روش | مسیر | توضیح |
|-----|------|--------|
| GET | `/profile` | پروفایل + تنظیمات |
| PUT/POST | `/profile` | ویرایش نام/سن/مدرسه/... |
| PUT/POST | `/profile/settings` | صدا و تکرار سوره |
| POST | `/profile/avatar` | آپلود آواتار (base64 یا multipart) |
| POST | `/profile/avatar/remove` | حذف آواتار |
| GET | `/auth/me` | پروفایل نشست (+ `avatarUrl`) |

### آپلود آواتار

```json
POST /profile/avatar
Authorization: Bearer <token>
{
  "image_base64": "<base64 یا data:image/jpeg;base64,...>",
  "mime_type": "image/jpeg"
}
```

فایل در `public/uploads/avatars/` ذخیره می‌شود و URL عمومی برمی‌گردد.

### تنظیمات

```json
{
  "soundEffectsEnabled": true,
  "surahListenRepeatCount": 3
}
```

## پشتیبانی

| روش | مسیر | توضیح |
|-----|------|--------|
| GET | `/support/tickets` | لیست تیکیت‌های کاربر |
| GET | `/support/tickets/get?id=` | جزئیات |
| POST | `/support/tickets` | ایجاد |
| POST | `/support/tickets/message` | پاسخ کاربر |
| GET | `/admin/support/tickets` | لیست ادمین (`X-Admin-Key`) |
| POST | `/admin/support/reply` | پاسخ پشتیبانی |
| POST | `/admin/support/status` | تغییر وضعیت |

وضعیت‌ها: `open` | `answered` | `closed`  
دسته‌ها: `ticket` | `quick_message`

پنل ادمین: `public/admin/support.html`

## کلاینت

- `src/services/profile/*`
- `src/services/support/supportService.ts`
- `ProfileSyncBridge` — همگام بعد از لاگین
- نام/تنظیمات/آواتار/تیکیت هنگام لاگین واقعی به سرور می‌روند

## تست

```bash
php scripts/test_profile_support.php
```
