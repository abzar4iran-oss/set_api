<?php
declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

final class ZarinpalPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(private array $config)
    {
    }

    public function name(): string
    {
        return 'zarinpal';
    }

    public function requestPayment(array $order, string $callbackUrl): array
    {
        $merchant = trim((string) ($this->config['merchant_id'] ?? ''));
        if ($merchant === '') {
            throw new RuntimeException('Merchant ID زرین‌پال تنظیم نشده است.');
        }

        $sandbox = !empty($this->config['sandbox']);
        $endpoint = $sandbox
            ? 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
            : 'https://api.zarinpal.com/pg/v4/payment/request.json';

        $prefix = (string) ($this->config['description_prefix'] ?? 'آل‌نجم ثاقب');
        $payload = [
            'merchant_id' => $merchant,
            'amount' => (int) $order['amount_irr'],
            'callback_url' => $callbackUrl,
            'description' => $prefix . ' — ' . (string) $order['product_title'],
            'metadata' => [
                'order_id' => (string) $order['order_token'],
                'mobile' => '',
                'email' => '',
            ],
        ];

        $response = $this->postJson($endpoint, $payload);
        $code = (int) ($response['data']['code'] ?? $response['errors']['code'] ?? 0);
        $authority = (string) ($response['data']['authority'] ?? '');

        if ($code !== 100 || $authority === '') {
            $message = (string) ($response['errors']['message'] ?? $response['data']['message'] ?? 'خطای زرین‌پال');
            throw new RuntimeException('درخواست پرداخت زرین‌پال ناموفق: ' . $message);
        }

        $startPay = $sandbox
            ? 'https://sandbox.zarinpal.com/pg/StartPay/' . $authority
            : 'https://www.zarinpal.com/pg/StartPay/' . $authority;

        return [
            'authority' => $authority,
            'payment_url' => $startPay,
            'raw' => $response,
        ];
    }

    public function verifyPayment(array $order, string $authority): array
    {
        $merchant = trim((string) ($this->config['merchant_id'] ?? ''));
        if ($merchant === '') {
            return ['ok' => false, 'message' => 'Merchant ID زرین‌پال تنظیم نشده است.'];
        }

        $sandbox = !empty($this->config['sandbox']);
        $endpoint = $sandbox
            ? 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json'
            : 'https://api.zarinpal.com/pg/v4/payment/verify.json';

        $response = $this->postJson($endpoint, [
            'merchant_id' => $merchant,
            'amount' => (int) $order['amount_irr'],
            'authority' => $authority,
        ]);

        $code = (int) ($response['data']['code'] ?? 0);
        // 100 = success, 101 = already verified
        if ($code !== 100 && $code !== 101) {
            $message = (string) ($response['errors']['message'] ?? $response['data']['message'] ?? 'تأیید پرداخت ناموفق');
            return ['ok' => false, 'message' => $message, 'raw' => $response];
        }

        return [
            'ok' => true,
            'ref_id' => (string) ($response['data']['ref_id'] ?? ''),
            'raw' => $response,
        ];
    }

    /** @return array<string, mixed> */
    private function postJson(string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('راه‌اندازی CURL ناموفق بود.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || !is_string($body)) {
            throw new RuntimeException('ارتباط با زرین‌پال برقرار نشد: ' . $error);
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
