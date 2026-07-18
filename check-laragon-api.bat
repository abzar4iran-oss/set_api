@echo off
echo === Al Najmo API on Laragon ===
curl -s http://127.0.0.1/api/public/health
echo.
echo.
echo Health:  http://localhost/api/public/health
echo Lessons: http://localhost/api/public/lessons
echo Admin:   http://localhost/api/public/admin/config.html
echo Media:   http://localhost/api/public/media/voice/
echo.
echo OTP demo code: 123456
echo App .env:
echo   EXPO_PUBLIC_API_URL=http://localhost/api/public
echo   EXPO_PUBLIC_MEDIA_BASE_URL=http://localhost/api/public/media
pause
