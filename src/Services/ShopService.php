<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ShopOrderRepository;
use App\Services\Payments\MockPaymentGateway;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\ZarinpalPaymentGateway;
use RuntimeException;

final class ShopService
{
    private PaymentGatewayInterface $gateway;

    public function __construct(
        private array $appConfig,
        private ShopOrderRepository $orders,
        private ProgressService $progress,
        private RemoteConfigService $remoteConfig,
        private ?NotificationService $notifications = null
    ) {
        $this->gateway = $this->makeGateway();
    }

    public function catalog(): array
    {
        $this->assertShopEnabled();
        $shop = $this->shopConfig();
        $vip = is_array($shop['vip_plan'] ?? null) ? $shop['vip_plan'] : [];
        $packs = is_array($shop['coin_packs'] ?? null) ? $shop['coin_packs'] : [];

        $coinPacks = [];
        foreach ($packs as $pack) {
            if (!is_array($pack) || isset($pack['active']) && empty($pack['active'])) {
                continue;
            }
            $coinPacks[] = $this->mapPack($pack);
        }

        $vipPlan = null;
        if ($vip !== [] && (!isset($vip['active']) || !empty($vip['active']))) {
            $vipPlan = $this->mapVip($vip);
        }

        $payments = $this->appConfig['payments'] ?? [];

        return [
            'enabled' => true,
            'provider' => $this->gateway->name(),
            'currency' => 'IRR',
            'vip_plan' => $vipPlan,
            'coin_packs' => $coinPacks,
            'allow_direct_grant' => !empty($payments['allow_direct_grant']),
        ];
    }

    public function createOrder(int $userId, array $payload): array
    {
        $this->assertShopEnabled();

        $productType = strtolower(trim((string) ($payload['product_type'] ?? $payload['productType'] ?? '')));
        $productId = trim((string) ($payload['product_id'] ?? $payload['productId'] ?? ''));
        $clientRequestId = trim((string) ($payload['client_request_id'] ?? $payload['clientRequestId'] ?? ''));

        if (!in_array($productType, ['coin_pack', 'vip'], true)) {
            throw new RuntimeException('product_type باید coin_pack یا vip باشد.');
        }
        if ($productId === '') {
            throw new RuntimeException('product_id الزامی است.');
        }

        if ($clientRequestId !== '') {
            $existing = $this->orders->findByClientRequestId($userId, $clientRequestId);
            if ($existing) {
                return [
                    'order' => $this->mapOrder($existing),
                    'payment_url' => $existing['payment_url'] ?? null,
                    'authority' => $existing['provider_authority'] ?? null,
                    'progress' => $this->progress->getMappedProgress($userId),
                    'idempotent' => true,
                ];
            }
        }

        $product = $this->resolveProduct($productType, $productId);
        $ttl = (int) ($this->appConfig['payments']['order_ttl_minutes'] ?? 30);
        $orderToken = bin2hex(random_bytes(24));

        $row = $this->orders->create([
            'user_id' => $userId,
            'order_token' => $orderToken,
            'client_request_id' => $clientRequestId !== '' ? $clientRequestId : null,
            'product_type' => $productType,
            'product_id' => $productId,
            'product_title' => $product['title'],
            'amount_irr' => $product['amount_irr'],
            'currency' => 'IRR',
            'coins_grant' => $product['coins_grant'],
            'vip_days' => $product['vip_days'],
            'status' => 'pending',
            'provider' => $this->gateway->name(),
            'expires_at' => date('Y-m-d H:i:s', time() + max(5, $ttl) * 60),
            'meta_json' => json_encode(['product' => $product], JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);

        $callbackUrl = $this->callbackUrl();
        $payment = $this->gateway->requestPayment($row, $callbackUrl);

        $row = $this->orders->update((int) $row['id'], [
            'provider_authority' => $payment['authority'],
            'payment_url' => $payment['payment_url'],
            'status' => 'awaiting_payment',
            'meta_json' => json_encode([
                'product' => $product,
                'gateway_request' => $payment['raw'] ?? null,
            ], JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);

        return [
            'order' => $this->mapOrder($row),
            'payment_url' => $payment['payment_url'],
            'authority' => $payment['authority'],
            'progress' => $this->progress->getMappedProgress($userId),
            'idempotent' => false,
        ];
    }

    public function getOrder(int $userId, string $token): array
    {
        $row = $this->requireUserOrder($userId, $token);
        return [
            'order' => $this->mapOrder($row),
            'progress' => $this->progress->getMappedProgress($userId),
        ];
    }

    public function history(int $userId, int $limit = 30): array
    {
        $rows = $this->orders->listForUser($userId, $limit);
        return [
            'orders' => array_map(fn (array $row) => $this->mapOrder($row), $rows),
        ];
    }

    public function verify(int $userId, array $payload): array
    {
        $token = trim((string) ($payload['order_token'] ?? $payload['orderToken'] ?? ''));
        $authority = trim((string) ($payload['authority'] ?? ''));

        $row = null;
        if ($token !== '') {
            $row = $this->requireUserOrder($userId, $token);
        } elseif ($authority !== '') {
            $row = $this->orders->findByAuthority($authority);
            if (!$row || (int) $row['user_id'] !== $userId) {
                throw new RuntimeException('سفارش یافت نشد.');
            }
        } else {
            throw new RuntimeException('order_token یا authority الزامی است.');
        }

        return $this->completePayment($row, $authority !== '' ? $authority : (string) $row['provider_authority'], true);
    }

    /** Callback درگاه — بدون نیاز به Bearer (با authority) */
    public function handleCallback(string $authority, string $status): array
    {
        $row = $this->orders->findByAuthority($authority);
        if (!$row) {
            throw new RuntimeException('سفارش با این authority یافت نشد.');
        }

        if (strtoupper($status) !== 'OK') {
            $this->orders->update((int) $row['id'], ['status' => 'failed']);
            return [
                'ok' => false,
                'order' => $this->mapOrder($this->orders->findById((int) $row['id']) ?? $row),
                'message' => 'پرداخت توسط کاربر لغو یا ناموفق شد.',
            ];
        }

        return $this->completePayment($row, $authority, false);
    }

    /** تکمیل پرداخت آزمایشی (mock) */
    public function mockComplete(int $userId, string $orderToken): array
    {
        if ($this->gateway->name() !== 'mock') {
            throw new RuntimeException('تکمیل آزمایشی فقط در حالت mock مجاز است.');
        }
        $row = $this->requireUserOrder($userId, $orderToken);
        return $this->completePayment($row, (string) $row['provider_authority'], true);
    }

    /** @return array{ok:bool,order:array,progress?:array,message?:string,already_fulfilled?:bool} */
    private function completePayment(array $row, string $authority, bool $includeProgress): array
    {
        if (in_array((string) $row['status'], ['paid', 'fulfilled'], true)) {
            $out = [
                'ok' => true,
                'order' => $this->mapOrder($row),
                'already_fulfilled' => true,
                'message' => 'این سفارش قبلاً تکمیل شده است.',
            ];
            if ($includeProgress) {
                $out['progress'] = $this->progress->getMappedProgress((int) $row['user_id']);
            }
            return $out;
        }

        if (!in_array((string) $row['status'], ['awaiting_payment', 'pending', 'verifying'], true)) {
            throw new RuntimeException('وضعیت سفارش برای تأیید نامعتبر است.');
        }

        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            $this->orders->update((int) $row['id'], ['status' => 'expired']);
            throw new RuntimeException('مهلت پرداخت این سفارش تمام شده است.');
        }

        $this->orders->update((int) $row['id'], ['status' => 'verifying']);
        $verify = $this->gateway->verifyPayment($row, $authority);
        if (empty($verify['ok'])) {
            $this->orders->update((int) $row['id'], ['status' => 'failed']);
            throw new RuntimeException($verify['message'] ?? 'تأیید پرداخت ناموفق بود.');
        }

        $now = date('Y-m-d H:i:s');
        $row = $this->orders->update((int) $row['id'], [
            'status' => 'paid',
            'provider_ref_id' => $verify['ref_id'] ?? null,
            'paid_at' => $now,
            'meta_json' => json_encode([
                'gateway_verify' => $verify['raw'] ?? null,
            ], JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);

        $fulfill = $this->progress->fulfillShopOrder(
            (int) $row['user_id'],
            (int) $row['coins_grant'],
            (int) $row['vip_days'],
            (string) $row['order_token']
        );

        $row = $this->orders->update((int) $row['id'], [
            'status' => 'fulfilled',
            'fulfilled_at' => date('Y-m-d H:i:s'),
        ]);

        $this->notifications?->notifyShopFulfilled(
            (int) $row['user_id'],
            (string) $row['order_token'],
            (int) $row['coins_grant'],
            (int) $row['vip_days']
        );

        $out = [
            'ok' => true,
            'order' => $this->mapOrder($row),
            'already_fulfilled' => false,
            'message' => 'پرداخت تأیید و اعتبار اعمال شد.',
            'fulfill_meta' => $fulfill['meta'],
        ];
        if ($includeProgress) {
            $out['progress'] = $fulfill['progress'];
        }
        return $out;
    }

    /** @return array{title:string,amount_irr:int,coins_grant:int,vip_days:int} */
    private function resolveProduct(string $type, string $id): array
    {
        $shop = $this->shopConfig();
        if ($type === 'coin_pack') {
            foreach ($shop['coin_packs'] ?? [] as $pack) {
                if (!is_array($pack) || (string) ($pack['id'] ?? '') !== $id) {
                    continue;
                }
                if (isset($pack['active']) && empty($pack['active'])) {
                    throw new RuntimeException('این بسته سکه غیرفعال است.');
                }
                $amount = (int) ($pack['amount_irr'] ?? 0);
                $coins = (int) ($pack['coins'] ?? 0);
                if ($amount <= 0 || $coins <= 0) {
                    throw new RuntimeException('تعریف بسته سکه ناقص است (amount_irr/coins).');
                }
                return [
                    'title' => $coins . ' سکه',
                    'amount_irr' => $amount,
                    'coins_grant' => $coins,
                    'vip_days' => 0,
                ];
            }
            throw new RuntimeException('بسته سکه یافت نشد.');
        }

        $vip = is_array($shop['vip_plan'] ?? null) ? $shop['vip_plan'] : [];
        if ((string) ($vip['id'] ?? '') !== $id) {
            throw new RuntimeException('طرح VIP یافت نشد.');
        }
        if (isset($vip['active']) && empty($vip['active'])) {
            throw new RuntimeException('طرح VIP غیرفعال است.');
        }
        $amount = (int) ($vip['amount_irr'] ?? 0);
        $days = (int) ($vip['duration_days'] ?? 30);
        if ($amount <= 0 || $days <= 0) {
            throw new RuntimeException('تعریف VIP ناقص است (amount_irr/duration_days).');
        }
        return [
            'title' => (string) ($vip['title'] ?? 'اشتراک ویژه'),
            'amount_irr' => $amount,
            'coins_grant' => 0,
            'vip_days' => $days,
        ];
    }

    private function requireUserOrder(int $userId, string $token): array
    {
        if ($token === '') {
            throw new RuntimeException('order_token الزامی است.');
        }
        $row = $this->orders->findByToken($token);
        if (!$row || (int) $row['user_id'] !== $userId) {
            throw new RuntimeException('سفارش یافت نشد.');
        }
        return $row;
    }

    private function mapOrder(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'orderToken' => (string) $row['order_token'],
            'clientRequestId' => $row['client_request_id'] !== null ? (string) $row['client_request_id'] : null,
            'productType' => (string) $row['product_type'],
            'productId' => (string) $row['product_id'],
            'productTitle' => (string) $row['product_title'],
            'amountIrr' => (int) $row['amount_irr'],
            'currency' => (string) $row['currency'],
            'coinsGrant' => (int) $row['coins_grant'],
            'vipDays' => (int) $row['vip_days'],
            'status' => (string) $row['status'],
            'provider' => (string) $row['provider'],
            'authority' => $row['provider_authority'] !== null ? (string) $row['provider_authority'] : null,
            'refId' => $row['provider_ref_id'] !== null ? (string) $row['provider_ref_id'] : null,
            'paymentUrl' => $row['payment_url'] !== null ? (string) $row['payment_url'] : null,
            'paidAt' => $this->toIso($row['paid_at'] ?? null),
            'fulfilledAt' => $this->toIso($row['fulfilled_at'] ?? null),
            'expiresAt' => $this->toIso($row['expires_at'] ?? null),
            'createdAt' => $this->toIso($row['created_at'] ?? null),
        ];
    }

    private function mapPack(array $pack): array
    {
        return [
            'id' => (string) ($pack['id'] ?? ''),
            'coins' => (int) ($pack['coins'] ?? 0),
            'priceLabel' => (string) ($pack['price_label'] ?? $pack['priceLabel'] ?? ''),
            'hint' => (string) ($pack['hint'] ?? ''),
            'badge' => (string) ($pack['badge'] ?? $pack['badge_label'] ?? $pack['badgeLabel'] ?? ''),
            'amountIrr' => (int) ($pack['amount_irr'] ?? $pack['amountIrr'] ?? 0),
            'active' => !isset($pack['active']) || !empty($pack['active']),
        ];
    }

    private function mapVip(array $vip): array
    {
        return [
            'id' => (string) ($vip['id'] ?? ''),
            'title' => (string) ($vip['title'] ?? ''),
            'priceLabel' => (string) ($vip['price_label'] ?? $vip['priceLabel'] ?? ''),
            'description' => (string) ($vip['description'] ?? ''),
            'amountIrr' => (int) ($vip['amount_irr'] ?? $vip['amountIrr'] ?? 0),
            'durationDays' => (int) ($vip['duration_days'] ?? $vip['durationDays'] ?? 30),
            'active' => !isset($vip['active']) || !empty($vip['active']),
        ];
    }

    private function assertShopEnabled(): void
    {
        $features = $this->remoteConfig->getPublicConfig()['features'] ?? [];
        if (isset($features['shop_enabled']) && empty($features['shop_enabled'])) {
            throw new RuntimeException('فروشگاه فعلاً غیرفعال است.');
        }
    }

    private function shopConfig(): array
    {
        $config = $this->remoteConfig->getPublicConfig();
        return is_array($config['shop'] ?? null) ? $config['shop'] : [];
    }

    private function makeGateway(): PaymentGatewayInterface
    {
        $payments = $this->appConfig['payments'] ?? [];
        $provider = strtolower((string) ($payments['provider'] ?? 'mock'));
        $baseUrl = rtrim((string) ($this->appConfig['base_url'] ?? 'http://localhost/api/public'), '/');

        if ($provider === 'zarinpal') {
            return new ZarinpalPaymentGateway(is_array($payments['zarinpal'] ?? null) ? $payments['zarinpal'] : []);
        }

        return new MockPaymentGateway($baseUrl);
    }

    private function callbackUrl(): string
    {
        $base = rtrim((string) ($this->appConfig['base_url'] ?? ''), '/');
        $path = (string) ($this->appConfig['payments']['callback_path'] ?? '/shop/payments/callback');
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        return $base . $path;
    }

    private function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return date('c', strtotime((string) $value) ?: time());
    }

    public function appReturnUrl(array $order, bool $ok): string
    {
        $base = (string) ($this->appConfig['payments']['app_return_url'] ?? 'alnajmothagheb://shop/result');
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . http_build_query([
            'ok' => $ok ? '1' : '0',
            'order' => $order['orderToken'] ?? '',
            'status' => $order['status'] ?? '',
        ]);
    }
}
