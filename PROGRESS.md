# پیشرفت + اقتصاد (Progress & Economy)

منبع حقیقت برای سکه، قلب، امتیاز رقابت، حق VIP، مکان‌نما مسیر یادگیری، پرواز، تمرین شنیدن سوره، و وضعیت چالش روزانه.

## پیش‌نیاز
- احراز هویت با Bearer token (`Authorization: Bearer …`)
- جداول `user_progress` و `progress_events` با اولین درخواست ساخته می‌شوند
- مقادیر پاداش/جریمه از **Remote Config** خوانده می‌شود
- ترتیب دروس: `storage/lesson_path.json`

## Endpoints

### `GET /progress`
برمی‌گرداند snapshot فعلی + اعمال regen قلب.

```json
{
  "ok": true,
  "data": {
    "progress": {
      "revision": 3,
      "coins": 120,
      "hearts": 4,
      "competePoints": 14,
      "isPremium": false,
      "lastHeartRegenAt": 1710000000000,
      "currentLesson": 2,
      "pendingSurahAfterLesson": null,
      "takeoffPlatformCompleted": true,
      "matchesPlayed": 0,
      "dailyChallengeLockedUntil": null,
      "dailyChallengeWonDayKey": null,
      "dailyChallengePlayedDayKey": null,
      "dailyChallengePlayedQuestionIds": [],
      "updatedAt": "2026-07-11T…"
    },
    "is_new": false,
    "server_time_ms": 1710000000000
  }
}
```

- `is_new: true` یعنی `revision === 0` و هنوز اکشنی روی سرور نخورده → کلاینت می‌تواند `import` بزند.

### `POST /progress/import`
فقط وقتی `revision === 0`. بدنه: کل snapshot یا `{ "progress": { … } }` (camelCase یا snake_case).

برای انتقال پیشرفت مهمان/محلی به حساب واقعی بعد از لاگین.

### `POST /progress/action`
بدنه:

```json
{
  "action": "complete_lesson",
  "lesson_id": 1
}
```

یا `{ "action": "…", "payload": { … } }`.

| action | توضیح | فیلدهای مهم |
|--------|--------|-------------|
| `complete_lesson` | تکمیل درس مسیر + پاداش learn | `lesson_id` |
| `complete_takeoff` | تکمیل سکوی پرواز | — |
| `complete_surah_listen` | پاک کردن pending شنیدن | `after_lesson_id` |
| `wrong_answer` | جریمه سکه/قلب (با قواعد VIP) | — |
| `correct_answer` | پاداش امتیاز | — |
| `refill_hearts` | پر کردن قلب با سکه | — |
| `add_coins` | افزودن سکه | `amount`, `reason?` |
| `spend_coins` | کسر سکه | `amount`, `reason?` |
| `add_compete_points` | افزودن امتیاز | `amount` |
| `convert_points` | تبدیل امتیاز → سکه | `points` |
| `daily_challenge_win` | برد چالش روزانه | `day_key` (YYYY-MM-DD) |
| `daily_challenge_fail` | قفل تا فردا | — |
| `daily_challenge_played` | ثبت سوال بازی‌شده | `day_key`, `question_id` |
| `purchase_coin_pack` | اعتبار بسته (فعلاً بدون درگاه) | `pack_id` یا `coins` |
| `activate_premium` | فعال‌سازی VIP | — |
| `record_match_played` | +۱ مسابقه | — |
| `reset` | بازنشانی پیشرفت/اقتصاد به پیش‌فرض RC | — |

پاسخ شامل `progress` به‌روز، `action`، و `meta` است. هر اکشن `revision` را یکی بالا می‌برد و در `progress_events` لاگ می‌شود.

## سیاست sync کلاینت
1. مهمان محلی (`local:` token): فقط AsyncStorage — بدون API
2. بعد از لاگین واقعی: `GET /progress`
3. اگر `is_new` → `POST /progress/import` با state محلی
4. اگر سرور قدیمی‌تر نیست → اعمال snapshot سرور روی AppStore
5. اکشن‌های مهم: optimistic محلی + `POST /progress/action` و اعمال پاسخ سرور

## تست سریع (PowerShell)

```powershell
# بعد از گرفتن token از /auth/...
$h = @{ Authorization = "Bearer TOKEN"; "Content-Type" = "application/json" }
Invoke-RestMethod http://localhost/api/public/progress -Headers $h
Invoke-RestMethod http://localhost/api/public/progress/action -Method POST -Headers $h -Body '{"action":"add_coins","amount":10,"reason":"test"}'
```
