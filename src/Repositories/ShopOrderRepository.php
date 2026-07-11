<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ShopOrderRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM shop_orders WHERE order_token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM shop_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByAuthority(string $authority): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM shop_orders WHERE provider_authority = :authority LIMIT 1'
        );
        $stmt->execute(['authority' => $authority]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByClientRequestId(int $userId, string $clientRequestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM shop_orders
             WHERE user_id = :user_id AND client_request_id = :client_request_id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'client_request_id' => $clientRequestId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listForUser(int $userId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT * FROM shop_orders WHERE user_id = :user_id ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO shop_orders (
                user_id, order_token, client_request_id, product_type, product_id, product_title,
                amount_irr, currency, coins_grant, vip_days, status, provider, provider_authority,
                provider_ref_id, payment_url, meta_json, paid_at, fulfilled_at, expires_at,
                created_at, updated_at
            ) VALUES (
                :user_id, :order_token, :client_request_id, :product_type, :product_id, :product_title,
                :amount_irr, :currency, :coins_grant, :vip_days, :status, :provider, :provider_authority,
                :provider_ref_id, :payment_url, :meta_json, :paid_at, :fulfilled_at, :expires_at,
                :created_at, :updated_at
            )'
        );

        $stmt->execute([
            'user_id' => (int) $data['user_id'],
            'order_token' => $data['order_token'],
            'client_request_id' => $data['client_request_id'] ?? null,
            'product_type' => $data['product_type'],
            'product_id' => $data['product_id'],
            'product_title' => $data['product_title'],
            'amount_irr' => (int) $data['amount_irr'],
            'currency' => $data['currency'] ?? 'IRR',
            'coins_grant' => (int) ($data['coins_grant'] ?? 0),
            'vip_days' => (int) ($data['vip_days'] ?? 0),
            'status' => $data['status'],
            'provider' => $data['provider'],
            'provider_authority' => $data['provider_authority'] ?? null,
            'provider_ref_id' => $data['provider_ref_id'] ?? null,
            'payment_url' => $data['payment_url'] ?? null,
            'meta_json' => $data['meta_json'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'fulfilled_at' => $data['fulfilled_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByToken((string) $data['order_token']) ?? [];
    }

    public function update(int $id, array $fields): array
    {
        $allowed = [
            'status', 'provider_authority', 'provider_ref_id', 'payment_url', 'meta_json',
            'paid_at', 'fulfilled_at', 'expires_at',
        ];
        $sets = [];
        $params = ['id' => $id, 'updated_at' => date('Y-m-d H:i:s')];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $fields[$key];
        }
        $sets[] = 'updated_at = :updated_at';
        $sql = 'UPDATE shop_orders SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
        return $this->findById($id) ?? [];
    }
}
