<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NotificationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByPublicId(int $userId, string $publicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_notifications WHERE user_id = :uid AND public_id = :pid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listForUser(int $userId, bool $includeDismissed = false, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM user_notifications WHERE user_id = :uid';
        if (!$includeDismissed) {
            $sql .= ' AND dismissed_at IS NULL';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_notifications
             WHERE user_id = :uid AND dismissed_at IS NULL AND read_at IS NULL'
        );
        $stmt->execute(['uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_notifications (
                user_id, public_id, title, body, kind, source, deep_link, meta_json,
                read_at, dismissed_at, created_at, updated_at
            ) VALUES (
                :user_id, :public_id, :title, :body, :kind, :source, :deep_link, :meta_json,
                NULL, NULL, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'public_id' => $data['public_id'],
            'title' => $data['title'],
            'body' => $data['body'],
            'kind' => $data['kind'],
            'source' => $data['source'] ?? 'system',
            'deep_link' => $data['deep_link'] ?? null,
            'meta_json' => $data['meta_json'] ?? null,
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        return $this->findById($id) ?? [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_notifications WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markRead(int $userId, string $publicId): ?array
    {
        $row = $this->findByPublicId($userId, $publicId);
        if (!$row) {
            return null;
        }
        if (!empty($row['read_at'])) {
            return $row;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE user_notifications SET read_at = :read_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute(['read_at' => $now, 'updated_at' => $now, 'id' => $row['id']]);
        return $this->findById((int) $row['id']);
    }

    public function markAllRead(int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE user_notifications
             SET read_at = :read_at, updated_at = :updated_at
             WHERE user_id = :uid AND dismissed_at IS NULL AND read_at IS NULL'
        );
        $stmt->execute(['read_at' => $now, 'updated_at' => $now, 'uid' => $userId]);
        return $stmt->rowCount();
    }

    public function dismiss(int $userId, string $publicId): ?array
    {
        $row = $this->findByPublicId($userId, $publicId);
        if (!$row) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE user_notifications
             SET dismissed_at = :dismissed_at, read_at = COALESCE(read_at, :read_at), updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'dismissed_at' => $now,
            'read_at' => $now,
            'updated_at' => $now,
            'id' => $row['id'],
        ]);
        return $this->findById((int) $row['id']);
    }

    /** @return list<int> */
    public function allUserIds(): array
    {
        $rows = $this->pdo->query('SELECT id FROM users ORDER BY id ASC')->fetchAll();
        return array_map(static fn ($r) => (int) $r['id'], $rows ?: []);
    }
}
