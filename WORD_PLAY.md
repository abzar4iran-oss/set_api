# بازی با کلمات (Word Play)

پازل روی کلاینت ساخته می‌شود؛ پیشرفت، راهنما، سکه و امتیاز روی سرور ثبت می‌شوند.

## مسیرها

| Method | Path | توضیح |
|--------|------|--------|
| GET | `/word-play` | وضعیت سطح + کانفیگ |
| POST | `/word-play/start` | شروع تلاش مرحله |
| POST | `/word-play/hint` | خرج سکهٔ راهنما |
| POST | `/word-play/complete` | تکمیل مرحله و پاداش |

همه با `Authorization: Bearer …`

## بدنهٔ نمونه

```json
// POST /word-play/start
{ "stage_index": 2 }

// POST /word-play/hint
{ "attempt_token": "…" }

// POST /word-play/complete
{
  "attempt_token": "…",
  "target_count": 4,
  "bonus_count": 1,
  "found_targets_count": 4
}
```

## فیلدهای `user_progress`

- `word_play_level_index` — ایندکس ۰‌پایهٔ بالاترین مرحلهٔ باز
- `word_play_content_version` — برای ریست پس از بازطراحی محتوا

## پاداش‌ها (ثابت سرور)

- سکهٔ مرحله: `5`
- سکهٔ جایزه: `3 × bonus_count`
- هدیهٔ فصل: `10` اگر مرحله انتهای فصل باشد
- امتیاز: `120 + 30 × bonus_count`
- راهنما: `18` سکه
- هزینهٔ ورود: `0`
