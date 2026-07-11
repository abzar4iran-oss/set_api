<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SupportTicketRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createTicket(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_tickets (
                user_id, public_id, subject, category, status, priority,
                user_seen_at, created_at, updated_at
            ) VALUES (
                :user_id, :public_id, :subject, :category, :status, :priority,
                :user_seen_at, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'public_id' => $data['public_id'],
            'subject' => $data['subject'],
            'category' => $data['category'],
            'status' => $data['status'] ?? 'open',
            'priority' => $data['priority'] ?? 'normal',
            'user_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->findById((int) $this->pdo->lastInsertId()) ?? [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByPublicIdWithUser(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.phone, u.first_name, u.last_name
             FROM support_tickets t
             JOIN users u ON u.id = t.user_id
             WHERE t.public_id = :pid LIMIT 1'
        );
        $stmt->execute(['pid' => $publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_tickets WHERE public_id = :pid LIMIT 1');
        $stmt->execute(['pid' => $publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findForUser(int $userId, string $publicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_tickets WHERE user_id = :uid AND public_id = :pid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $publicId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_tickets WHERE user_id = :uid
             ORDER BY updated_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /** @return list<array> */
    public function listAll(int $limit = 100, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        if ($status !== null && $status !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT t.*, u.phone, u.first_name, u.last_name
                 FROM support_tickets t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.status = :status
                 ORDER BY t.updated_at DESC, t.id DESC LIMIT ' . $limit
            );
            $stmt->execute(['status' => $status]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT t.*, u.phone, u.first_name, u.last_name
                 FROM support_tickets t
                 JOIN users u ON u.id = t.user_id
                 ORDER BY t.updated_at DESC, t.id DESC LIMIT ' . $limit
            );
        }
        return $stmt ? ($stmt->fetchAll() ?: []) : [];
    }

    public function updateStatus(int $ticketId, string $status): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets SET status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $ticketId,
        ]);
        return $this->findById($ticketId) ?? [];
    }

    public function markSeen(int $ticketId): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets SET user_seen_at = :seen WHERE id = :id'
        );
        $stmt->execute(['seen' => $now, 'id' => $ticketId]);
        return $this->findById($ticketId) ?? [];
    }

    public function clearSeen(int $ticketId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets SET user_seen_at = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $ticketId]);
    }

    public function unreadCountForUser(int $userId): int
    {
        // تیکیت‌هایی که آخرین پیام از پشتیبانی است و هنوز دیده نشده
        $sql = "SELECT COUNT(*) FROM support_tickets t
                WHERE t.user_id = :uid
                  AND t.status <> 'closed'
                  AND EXISTS (
                    SELECT 1 FROM support_ticket_messages m
                    WHERE m.ticket_id = t.id
                      AND m.id = (
                        SELECT MAX(m2.id) FROM support_ticket_messages m2 WHERE m2.ticket_id = t.id
                      )
                      AND m.author = 'support'
                      AND (t.user_seen_at IS NULL OR m.created_at > t.user_seen_at)
                  )";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function touch(int $ticketId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE support_tickets SET updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute(['updated_at' => date('Y-m-d H:i:s'), 'id' => $ticketId]);
    }

    public function addMessage(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_ticket_messages (
                ticket_id, public_id, author, body, created_at
            ) VALUES (
                :ticket_id, :public_id, :author, :body, :created_at
            )'
        );
        $stmt->execute([
            'ticket_id' => $data['ticket_id'],
            'public_id' => $data['public_id'],
            'author' => $data['author'],
            'body' => $data['body'],
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $this->touch((int) $data['ticket_id']);
        return $this->findMessageById((int) $this->pdo->lastInsertId()) ?? [];
    }

    public function findMessageById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_ticket_messages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lastMessage(int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_ticket_messages WHERE ticket_id = :tid ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['tid' => $ticketId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listMessages(int $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_ticket_messages WHERE ticket_id = :tid ORDER BY id ASC'
        );
        $stmt->execute(['tid' => $ticketId]);
        return $stmt->fetchAll() ?: [];
    }
}
