<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PushTokenRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function upsert(int $userId, string $token, ?string $platform, ?string $deviceId, ?string $appVersion): array
    {
        $existing = $this->findByToken($token);
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE user_push_tokens SET
                    user_id = :user_id,
                    platform = :platform,
                    device_id = :device_id,
                    app_version = :app_version,
                    last_seen_at = :last_seen_at,
                    updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'user_id' => $userId,
                'platform' => $platform,
                'device_id' => $deviceId,
                'app_version' => $appVersion,
                'last_seen_at' => $now,
                'updated_at' => $now,
                'id' => $existing['id'],
            ]);
            return $this->findById((int) $existing['id']) ?? [];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_push_tokens (
                user_id, token, platform, device_id, app_version, last_seen_at, created_at, updated_at
            ) VALUES (
                :user_id, :token, :platform, :device_id, :app_version, :last_seen_at, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'platform' => $platform,
            'device_id' => $deviceId,
            'app_version' => $appVersion,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? [];
    }

    public function deleteByToken(int $userId, string $token): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_push_tokens WHERE user_id = :uid AND token = :token'
        );
        $stmt->execute(['uid' => $userId, 'token' => $token]);
        return $stmt->rowCount() > 0;
    }

    public function deleteAllForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_push_tokens WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        return $stmt->rowCount();
    }

    /** @return list<array> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_push_tokens WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_push_tokens WHERE token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_push_tokens WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
