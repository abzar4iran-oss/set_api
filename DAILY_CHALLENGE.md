# چالش روزانه (Daily Challenge)

منبع حقیقت برای روز تقویمی، یک‌بار تلاش، قفل تا فردا، پاداش سکه/امتیاز، و ثبت تلاش.

منطقه زمانی سرور: **Asia/Tehran**

## پیش‌نیاز
- Bearer token
- Remote Config: `features.daily_challenge_enabled` و بلوک `daily_challenge`
- پیشرفت کاربر (`user_progress`) برای پاداش و قفل

## فلوی کامل

```
GET  /daily-challenge          → وضعیت امروز
POST /daily-challenge/start    → ساخت/ازسرگیری attempt + seed انتخاب سوال
     کلاینت سوال را از کاتالوگ محلی با seed برمی‌گزیند
POST /daily-challenge/commit   → قفل question_id + correct_answer + time_limit
POST /daily-challenge/submit   → ثبت نتیجه + پاداش/قفل در progress
```

UI تمرین داخل اپ می‌ماند؛ سرور جلسه، زمان، یک‌بار تلاش و اقتصاد را کنترل می‌کند.

## Endpoints

### `GET /daily-challenge`
```json
{
  "day_key": "2026-07-11",
  "timezone": "Asia/Tehran",
  "can_play": true,
  "won_today": false,
  "played_today": false,
  "one_attempt_per_day": true,
  "reward_coins": 20,
  "win_points": 1,
  "standard_seconds": 25,
  "easy_question_seconds": 12,
  "played_question_ids": [],
  "active_attempt": null,
  "progress": { "...": "ServerProgressSnapshot" }
}
```

### `POST /daily-challenge/start`
بدنه: `{ "max_lesson_id": 12 }`

پاسخ شامل `attempt.attemptToken`، `pick.seed`، و `exclude_question_ids`.

### `POST /daily-challenge/commit`
```json
{
  "attempt_token": "...",
  "question_id": "ex-...",
  "method_id": "fill_blank",
  "lesson_id": 8,
  "correct_answer": "...",
  "correct_option_ids": ["opt-1"],
  "time_limit_seconds": 25,
  "is_voice": false
}
```

- `lesson_id` نباید از `max_lesson_id` تلاش بیشتر باشد
- سوال صوتی: `is_voice=true` / `method_id=voice_teaching`

### `POST /daily-challenge/submit`
```json
{
  "attempt_token": "...",
  "client_correct": true,
  "timed_out": false,
  "answer": "",
  "option_id": ""
}
```

اولویت نمره‌دهی:
1. `timed_out` یا گذشتن از `time_limit + 5s` از commit → باخت
2. اگر `answer` / `option_id` باشد → مقایسه با پاسخ قفل‌شده
3. وگرنه `client_correct` (سازگار با UI فعلی تمرین‌ها)

در حالت `one_attempt_per_day`: برد/باخت → قفل تا نیمه‌شب فردا تهران + به‌روزرسانی progress.

## جدول `daily_challenge_attempts`
وضعیت‌ها: `open` → `committed` → `won` | `lost` | `expired`

## Remote Config
| کلید | معنی |
|------|------|
| `reward_coins` | سکه برد |
| `win_points` | امتیاز رقابت برد |
| `one_attempt_per_day` | یک تلاش در روز |
| `standard_seconds` / `easy_question_seconds` | زمان سوال |

## کلاینت
- کاربر لاگین واقعی: فلوی سرور در `DailyChallengeScreen`
- مهمان `local:`: همان فلوی محلی قبلی
- بعد از submit: `applyServerProgress`

## تست سریع
```powershell
$h = @{ Authorization = "Bearer TOKEN"; "Content-Type" = "application/json" }
Invoke-RestMethod http://localhost/api/public/daily-challenge -Headers $h
Invoke-RestMethod http://localhost/api/public/daily-challenge/start -Method POST -Headers $h -Body '{"max_lesson_id":10}'
```
