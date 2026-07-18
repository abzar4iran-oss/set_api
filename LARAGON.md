# API روی Laragon

## نصب انجام‌شده
- Junction: `C:\laragon\www\api` → `D:\api`
- Apache DocumentRoot: `C:\laragon\www`
- آدرس API: `http://localhost/api/public`
- اپ: `EXPO_PUBLIC_API_URL=http://localhost/api/public`

## هر بار اجرا
۱. Laragon را باز کن و **Start All** بزن (بهترین حالت)
۲. یا این فایل را اجرا کن:
   `D:\api\start-laragon-apache.bat`

۳. تست:
   http://localhost/api/public/health

## نکته
اگر فقط `httpd.exe` را بدون PATH لارگون اجرا کنی، SQLite لود نمی‌شود.
با Start All خود لارگون یا `start-laragon-apache.bat` این مشکل نیست.

## MySQL (اختیاری)
فعلاً SQLite فعال است (auto-migrate — نیازی به import دستی نیست).
اگر MySQL خواستید، بعد از Start All لارگون:
```sql
source D:/api/database/schema.sql
```
سپس در `config/config.php` مقدار `driver` را `mysql` کن.
توجه: حتی بدون import هم با اولین درخواست، `Database::migrate()` جداول را می‌سازد.
