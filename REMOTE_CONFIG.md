# Remote Config

## اندپوینت‌ها
- `GET /config` — عمومی (اپ می‌خواند)
- `PUT /config` یا `POST /config` — ادمین (هدر `X-Admin-Key`)
- `POST /config/reset` — بازگشت به پیش‌فرض

## پنل ساده
باز کنید:
`http://localhost/api/public/admin/config.html`

کلید ادمین پیش‌فرض در `config/config.php`:
`alnajmo-admin-change-me-2026`

## فایل‌ها
- فعال: `storage/remote_config.json`
- پیش‌فرض: `storage/remote_config.defaults.json`

## چه چیزهایی از سرور کنترل می‌شود
- روشن/خاموش مسابقه و چالش
- unlock_all_stages / دکمه‌های طراح
- سکه/قلب/جریمه/پاداش درس
- جایزه چالش روزانه و هزینه مسابقه
- قیمت VIP و بسته‌های سکه
- تکرار شنیدن سوره
- حالت نگهداری، بنر سراسری، حداقل نسخه

اپ در استارت config را می‌گیرد و کش می‌کند؛ اگر سرور قطع باشد از کش/پیش‌فرض استفاده می‌کند.
