@echo off
REM Deploy / verify Remote Config for local API
cd /d "%~dp0"

set PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
if not exist "%PHP%" (
  where php >nul 2>nul && set PHP=php
)

echo [1] Checking storage\remote_config.json ...
"%PHP%" -r "$c=json_decode(file_get_contents('storage/remote_config.json'),true); echo 'version='.$c['version'].' heart='.$c['economy']['heart_refill_coin_cost'].' win='.$c['compete']['win_reward_coins'].' vip='.($c['shop']['vip_plan']['active']?'true':'false').PHP_EOL; exit(($c['version']??0)>=5?0:1);"
if errorlevel 1 (
  echo Remote config is outdated or invalid.
  exit /b 1
)

echo [2] Syncing defaults ...
copy /Y "storage\remote_config.json" "storage\remote_config.defaults.json" >nul

echo [3] Ensuring API is reachable on :8080 ...
curl -s -o NUL -w "HTTP %%{http_code}\n" http://127.0.0.1:8080/config
if errorlevel 1 (
  echo Starting PHP server on 0.0.0.0:8080 ...
  start "AlNajmo-API" /MIN "%PHP%" -S 0.0.0.0:8080 -t public public/router.php
  timeout /t 2 >nul
)

curl -s http://127.0.0.1:8080/config | "%PHP%" -r "$j=json_decode(stream_get_contents(STDIN),true); $c=$j['data']['config']??[]; echo 'LIVE version='.($c['version']??'?').' heart='.($c['economy']['heart_refill_coin_cost']??'?').' packs='.count($c['shop']['coin_packs']??[]).PHP_EOL;"

echo Done. Point EXPO_PUBLIC_API_URL to http://YOUR_LAN_IP:8080
