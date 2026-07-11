# مسابقه زنده + نتیجه (Live Compete Match)

منبع حقیقت برای هزینه ورود، حریف، seed سوالات، امتیاز راندها، نتیجه (برد/مساوی/باخت)، پاداش سکه/امتیاز، شمارنده مسابقات، تاریخچه و لیدربورد.

## فلوی کامل

```
POST /matches/start     → کسر entry fee + ساخت match + opponent + seed
POST /matches/commit    → قفل ۵ راند (question_id / پاسخ)
  … بازی در کلاینت با bot محلی روی seed سرور …
POST /matches/finish    → محاسبه امتیاز از نتایج راند + پاداش + matches_played++
POST /matches/cancel    → فقط در وضعیت open: بازگشت entry fee
GET  /matches/active
GET  /matches/get?token=
GET  /matches/history
GET  /compete/leaderboard
```

## Endpoints

### `POST /matches/start`
```json
{ "max_lesson_id": 12 }
```
پاسخ: `match` (شامل `matchToken`, `seed`, `opponent`, `entryFee`), `config`, `progress`, `search_ms`

### `POST /matches/commit`
```json
{
  "match_token": "...",
  "rounds": [
    {
      "round_index": 0,
      "question_id": "ex-1",
      "method_id": "fill_blank",
      "lesson_id": 5,
      "correct_answer": "...",
      "correct_option_ids": ["a"]
    }
  ]
}
```
تعداد راند باید برابر `round_count` (۵) باشد.

### `POST /matches/finish`
```json
{
  "match_token": "...",
  "forfeited": false,
  "rounds": [
    {
      "round_index": 0,
      "player_correct": true,
      "player_time_ms": 4200,
      "opponent_correct": false,
      "opponent_time_ms": null
    }
  ]
}
```

سرور با همان منطق `resolveRoundWinner` امتیاز می‌دهد:
- هر دو درست → سریع‌تر برنده راند
- یکی درست → همان طرف
- سپس `win` / `draw` / `loss`
- پاداش از Remote Config: `win_reward_coins` / `draw_reward_coins`
- امتیاز رقابت = `player_score`
- `matches_played += 1` اتمیک با `compete_match_settle`

Idempotent: اگر قبلاً finished باشد، همان نتیجه برمی‌گردد.

### `POST /matches/cancel`
فقط `open` → refund entry fee. بعد از `commit` هزینه برنمی‌گردد (انصراف وسط بازی).

### `GET /compete/leaderboard?limit=10`
رتبه‌بندی بر اساس `compete_points` کاربران واقعی.

## وضعیت‌های مسابقه
`open` → `committed` → `finished` | `cancelled` | `expired`

TTL فعال: ۴۵ دقیقه (open → refund / committed → loss settle)

## کلاینت
- لاگین واقعی: MatchTab → start سرور → matchmaking با opponent سرور → duel با commit/finish
- مهمان: فلوی محلی قبلی (هزینه با `trySpendCoins`)
- نتیجه: اگر `serverSettled=1` پاداش محلی دوباره اعمال نمی‌شود
- لیدربورد: در صورت لاگین از API؛ وگرنه mock محلی

## Remote Config
`compete.entry_fee_coins`, `win_reward_coins`, `draw_reward_coins` + `features.compete_enabled`
