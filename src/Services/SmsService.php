<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SmsService
{
    public function __construct(private array $config)
    {
    }

    public function sendOtp(string $phone, string $code): void
    {
        $provider = $this->config['sms']['provider'] ?? 'log';

        if ($provider === 'kavenegar') {
            $this->sendKavenegar($phone, $code);
            return;
        }

        $this->log($phone, $code, 'local-log-only');
    }

    private function log(string $phone, string $codeOrNote, string $note = ''): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] phone=%s detail=%s %s\n",
            date('c'),
            $phone,
            $codeOrNote,
            $note
        );
        file_put_contents($dir . '/sms.log', $line, FILE_APPEND);
    }

    private function sendKavenegar(string $phone, string $code): void
    {
        $cfg = $this->config['sms']['kavenegar'] ?? [];
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('کلید API کاوه‌نگار تنظیم نشده است.');
        }

        $template = trim((string) ($cfg['template'] ?? ''));
        $sender = trim((string) ($cfg['sender'] ?? ''));

        if ($template !== '') {
            $result = $this->kavenegarRequest($apiKey, 'verify/lookup.json', [
                'receptor' => $phone,
                'token' => $code,
                'template' => $template,
            ]);
        } else {
            if ($sender === '') {
                throw new RuntimeException(
                    'شماره خط کاوه‌نگار (sender) تنظیم نشده است. ' .
                    'در پنل از بخش خطوط، شماره را کپی کنید و در config.php داخل sms.kavenegar.sender بگذارید، ' .
                    'یا یک قالب Verify Lookup بسازید و نامش را در sms.kavenegar.template بنویسید.'
                );
            }

            $result = $this->kavenegarRequest($apiKey, 'sms/send.json', [
                'receptor' => $phone,
                'sender' => $sender,
                'message' => "کد تأیید النجم ثاقب: {$code}",
            ]);
        }

        $status = (int) ($result['return']['status'] ?? 0);
        if ($status !== 200) {
            $msg = (string) ($result['return']['message'] ?? 'ارسال پیامک ناموفق بود.');
            $this->log($phone, 'kavenegar-failed', $msg);
            throw new RuntimeException($this->mapKavenegarError($msg, $status));
        }

        $this->log($phone, 'sent-via-kavenegar', $template !== '' ? "template={$template}" : "sender={$sender}");
    }

    /** @return array<string, mixed> */
    private function kavenegarRequest(string $apiKey, string $endpoint, array $params): array
    {
        $url = sprintf('https://api.kavenegar.com/v1/%s/%s', rawurlencode($apiKey), ltrim($endpoint, '/'));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('ارتباط با کاوه‌نگار برقرار نشد: ' . $error);
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new RuntimeException('پاسخ کاوه‌نگار نامعتبر است (HTTP ' . $http . ').');
        }

        return $json;
    }

    private function mapKavenegarError(string $message, int $status): string
    {
        return match ($status) {
            411 => 'گیرنده معتبر نیست. شماره موبایل را بررسی کنید.',
            412 => 'فرستنده معتبر نیست. شماره خط (sender) را در config درست وارد کنید.',
            413 => 'پیام خالی است.',
            414 => 'حساب شما اعتبار کافی ندارد.',
            417 => 'تاریخ معتبر نیست.',
            418 => 'حساب شما مسدود شده است.',
            419 => 'طول پیامک بیش از حد مجاز است.',
            422 => 'داده نامعتبر است (قالب یا پارامترها را در پنل کاوه‌نگار بررسی کنید).',
            424 => 'الگوی (template) پیدا نشد یا هنوز تأیید نشده است.',
            426 => 'استفاده از این روش برای حساب شما مجاز نیست.',
            428 => 'آدرس IP شما مجاز نیست.',
            431 => 'کد API نادرست است.',
            432 => 'سرویس فعال نیست.',
            default => $message !== '' ? $message : 'ارسال پیامک ناموفق بود.',
        };
    }
}
