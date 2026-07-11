@echo off
REM Start Apache for Al Najmo API with correct PHP PATH (Laragon)
SET "LARAGON=C:\laragon"
SET "PHP_DIR=%LARAGON%\bin\php\php-8.3.30-Win32-vs16-x64"
SET "HTTPD_DIR=%LARAGON%\bin\apache\httpd-2.4.66-260223-Win64-VS18\bin"
SET "PATH=%PHP_DIR%;%HTTPD_DIR%;%LARAGON%\bin;%PATH%"
SET "PHPRC=%PHP_DIR%"

echo Stopping old httpd (if any)...
taskkill /F /IM httpd.exe >nul 2>nul
timeout /t 1 /nobreak >nul

echo Starting Apache...
start "" /MIN "%HTTPD_DIR%\httpd.exe"
timeout /t 2 /nobreak >nul

echo.
echo API health:
curl -s http://127.0.0.1/api/public/health
echo.
echo.
echo Open: http://localhost/api/public/health
echo App .env should be: EXPO_PUBLIC_API_URL=http://localhost/api/public
echo.
pause
