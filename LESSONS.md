# کاتالوگ دروس (Lesson Catalog)

متادیتای سبک دروس روی سرور — بدون صوت/تمرین.

## اندپوینت‌ها (بدون لاگین)

| مسیر | توضیح |
|------|--------|
| `GET /lessons` | کل کاتالوگ + ترتیب مسیر |
| `GET /lessons/get?id=1` | یک درس |

## فایل داده

`storage/lessons_catalog.json`

تولید از اپ:

```bash
cd D:\Al_Najmo_Thagheb
npx tsx scripts/exportLessonsCatalog.ts
```

## فیلدها

- `title` / `focus` / `term` / `type`
- `media_folder` — پوشهٔ صوت روی CDN (`voice/{folder}/...`)
- `path_lesson_ids` — ترتیب مسیر یادگیری
- `media_base_url` — پایهٔ URL رسانه

## کلاینت

- `LessonCatalogSyncBridge` هنگام استارت کاتالوگ را می‌گیرد و کش می‌کند
- `getLessonById` عنوان/توضیح سرور را روی دادهٔ لوکال می‌نشاند
- با ورود به درس، `prefetchLessonMedia` چند صوت اول را دانلود می‌کند
