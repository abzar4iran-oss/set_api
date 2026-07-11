<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class TokenRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function createAuthToken(int $userId, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findValidAuthToken(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM auth_tokens
             WHERE token_hash = :hash
               AND revoked_at IS NULL
               AND expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'hash' => $tokenHash,
            'now' => date('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function touchAuthToken(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE auth_tokens SET last_used_at = :now WHERE id = :id');
        $stmt->execute(['id' => $id, 'now' => date('Y-m-d H:i:s')]);
    }

    public function revokeAuthToken(string $tokenHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE auth_tokens SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute([
            'now' => date('Y-m-d H:i:s'),
            'hash' => $tokenHash,
        ]);
    }

    public function createRegistrationToken(string $phone, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO registration_tokens (phone, token_hash, expires_at, created_at)
             VALUES (:phone, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'phone' => $phone,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findValidRegistrationToken(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM registration_tokens
             WHERE token_hash = :hash
               AND consumed_at IS NULL
               AND expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'hash' => $tokenHash,
            'now' => date('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function consumeRegistrationToken(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE registration_tokens SET consumed_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }
}
