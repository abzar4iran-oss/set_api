@echo off
REM Start API on http://0.0.0.0:8080
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
  echo PHP not found in PATH. Install PHP 8.1+ or XAMPP and try again.
  pause
  exit /b 1
)
echo Starting Al Najmo Thagheb API on http://0.0.0.0:8080 ...
php -S 0.0.0.0:8080 -t public public/router.php
