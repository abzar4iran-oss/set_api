<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\PushTokenRepository;

/**
 * ارسال پوش از طریق Expo Push API.
 * اگر توکنی نباشد یا ارسال خاموش باشد، هیچ خطایی پرتاب نمی‌کند.
 */
final class ExpoPushService
{
    public function __construct(
        private array $appConfig,
        private PushTokenRepository $pushTokens
    ) {
    }

    /** @return int تعداد توکن‌هایی که برای ارسال صف شدند */
    public function sendToUser(int $userId, array $notification): int
    {
        $cfg = $this->appConfig['notifications'] ?? [];
        if (isset($cfg['expo_push_enabled']) && empty($cfg['expo_push_enabled'])) {
            return 0;
        }

        $tokens = $this->pushTokens->listForUser($userId);
        if ($tokens === []) {
            return 0;
        }

        $messages = [];
        foreach ($tokens as $row) {
            $to = (string) ($row['token'] ?? '');
            if ($to === '' || !str_starts_with($to, 'ExponentPushToken[')) {
                // توکن‌های غیر Expo را فعلاً رد می‌کنیم ولی شمارش نمی‌کنیم
                if ($to !== '') {
                    $messages[] = $this->buildMessage($to, $notification);
                }
                continue;
            }
            $messages[] = $this->buildMessage($to, $notification);
        }

        if ($messages === []) {
            return 0;
        }

        $this->postMessages($messages);
        return count($messages);
    }

    private function buildMessage(string $to, array $notification): array
    {
        $sound = (string) (($this->appConfig['notifications']['push_sound'] ?? 'default') ?: 'default');
        return [
            'to' => $to,
            'title' => (string) ($notification['title'] ?? ''),
            'body' => (string) ($notification['body'] ?? ''),
            'sound' => $sound,
            'priority' => 'high',
            'data' => [
                'notificationId' => (string) ($notification['id'] ?? ''),
                'title' => (string) ($notification['title'] ?? ''),
                'body' => (string) ($notification['body'] ?? ''),
                'kind' => (string) ($notification['kind'] ?? 'lesson'),
                'deepLink' => $notification['deepLink'] ?? null,
            ],
        ];
    }

    /** @param list<array> $messages */
    private function postMessages(array $messages): void
    {
        $url = (string) (($this->appConfig['notifications']['expo_push_url'] ?? '')
            ?: 'https://exp.host/--/api/v2/push/send');

        $chunks = array_chunk($messages, 100);
        foreach ($chunks as $chunk) {
            $payload = json_encode($chunk, JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                continue;
            }

            if (!function_exists('curl_init')) {
                // بدون curl فقط لاگ می‌کنیم
                $this->log('expo_push_skip_no_curl', ['count' => count($chunk)]);
                continue;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Accept-Encoding: gzip, deflate',
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12,
            ]);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0 || $status >= 400) {
                $this->log('expo_push_failed', [
                    'status' => $status,
                    'errno' => $errno,
                    'response' => is_string($response) ? substr($response, 0, 500) : null,
                ]);
            }
        }
    }

    private function log(string $event, array $context): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = date('c') . ' ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents($dir . '/push.log', $line, FILE_APPEND);
    }
}
