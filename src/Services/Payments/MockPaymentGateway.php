<?php
declare(strict_types=1);

namespace App\Services\Payments;

final class MockPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(private string $baseUrl)
    {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function requestPayment(array $order, string $callbackUrl): array
    {
        $authority = 'MOCK-' . strtoupper(bin2hex(random_bytes(8)));
        $token = (string) ($order['order_token'] ?? '');
        $paymentUrl = rtrim($this->baseUrl, '/') . '/shop/payments/mock-pay?token=' . urlencode($token)
            . '&authority=' . urlencode($authority);

        return [
            'authority' => $authority,
            'payment_url' => $paymentUrl,
            'raw' => ['mode' => 'mock'],
        ];
    }

    public function verifyPayment(array $order, string $authority): array
    {
        if ($authority === '' || !str_starts_with($authority, 'MOCK-')) {
            return ['ok' => false, 'message' => 'authority نامعتبر است.'];
        }

        if ((string) ($order['provider_authority'] ?? '') !== $authority) {
            return ['ok' => false, 'message' => 'authority با سفارش مطابقت ندارد.'];
        }

        return [
            'ok' => true,
            'ref_id' => 'MOCK-REF-' . substr($authority, -8),
            'raw' => ['mode' => 'mock'],
        ];
    }
}
